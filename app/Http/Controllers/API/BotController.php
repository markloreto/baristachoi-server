<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Support\Facades\Hash;
use DB;
use Validator;
use Intervention\Image\ImageManagerStatic as Image;
use App\Services\PayUService\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Stream\Stream;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;
use OneSignal;
use OneSignalClient;

use Rap2hpoutre\FastExcel\FastExcel;

use App\Exports\ProductsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Jenssegers\Agent\Agent;
//
class BotController extends BaseController
{
    //BOT

    public function pricelist(Request $request){
      $data = $request->all();
      $type = $data["type"];
      
      $products = DB::table("pabile_products as pp")->select(DB::raw('IFNULL((SELECT `value` FROM pabile_product_specs WHERE product_id = pp.id AND `key` = 6), "N/A") AS `brand`, pp.name, ppc.name AS `category`, IFNULL((SELECT `value` FROM pabile_product_specs WHERE product_id = pp.id AND `key` = 1), "N/A") AS `weight`, IFNULL((SELECT `value` FROM pabile_product_specs WHERE product_id = pp.id AND `key` = 2), "N/A") AS `color`, IFNULL((SELECT `value` FROM pabile_product_specs WHERE product_id = pp.id AND `key` = 5), "N/A") AS `flavor`, pp.price'))
      ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
      ->join('pabile_product_main_categories AS ppmc', 'ppc.parent_id', '=', 'ppmc.id')
      ->get();
      $exportation = new ProductsExport($products);

      if($type == "PDF"){
        $format = "pdf";
        Excel::store($exportation, 'public/pricelist.pdf', null, \Maatwebsite\Excel\Excel::MPDF);
      }else{
        $format = "xlsx";
        Excel::store($exportation, 'public/pricelist.xlsx');
      }
      

      $json = json_decode('{
        "messages": [
          {
            "attachment": {
              "type": "file",
              "payload": {
                "url": "https://markloreto.xyz/storage/pricelist.'.$format.'"
              }
            }
          }
        ]
      }', true);

      /* if ($time >= $start && $time <= $end) {
        $json["redirect_to_blocks"] = ["profile check"];
      }else{
        $json["redirect_to_blocks"] = ["Beyond operating hours"];
      } */

      return response()->json($json);
    }

    public function botChekTimeDelivery(Request $request){
      $data = $request->all();

      $start = '07:00:00';
      $end   = '22:00:00';
      $now   = Carbon::now();
      $time  = $now->format('H:i:s');

      $json = json_decode('{
        
      }', true);

      if ($time >= $start && $time <= $end) {
        $json["redirect_to_blocks"] = ["profile check"];
      }else{
        $json["redirect_to_blocks"] = ["Beyond operating hours"];
      }

      return response()->json($json);
    }

    public function botSetBrgy(Request $request){
      $data = $request->all();
      $q = trim($data["q"]);
      $depotId = $data["depot_id"];
      $confirmation = (isset($data["confirmation"])) ? intval($data["confirmation"]) : false;

      $depotInfo = DB::table("pabile_depots")->where("id", $depotId)->first();
      $main = DB::table("locations")->select("name_3", "id_3", "varname_3")->where("id_2", $depotInfo->location_id)
      ->where(function ($query) use ($q){
        $query->where('name_3', 'like', "%" . $q . "%")->orWhere("varname_3", 'like', "%" . $q . "%");
      })->get();

      $json = json_decode('{
        
      }', true);

      if(count($main) == 1){
        $json["set_attributes"] = [
          "u-brgy_id" => $main[0]->id_3,
          "u-brgy_name" => (($main[0]->varname_3) ? $main[0]->name_3 . " *" .$main[0]->varname_3. "*" : $main[0]->name_3)
        ];

        if($confirmation){
          $json["redirect_to_blocks"] = ["confirm delivery"];
        }else{
          $json["redirect_to_blocks"] = ["profile done"];
        }
        
      }elseif(count($main) > 1){
        $json["redirect_to_blocks"] = ["multi brgy"];
      }else{
        $json["redirect_to_blocks"] = ["No Brgy"];
      }

      return response()->json($json);
    }

    public function botBrgyList(Request $request){
      $data = $request->all();

      $depotName = $data["depot_location"];
      $depotId = $data["depot_id"];

      $q = (isset($data["q"])) ? trim($data["q"]) : false;

      $depotInfo = DB::table("pabile_depots")->where("id", $depotId)->first();

      $records = DB::table("locations")->select(DB::raw("name_3 AS brgy, varname_3"))
      ->where("id_2", $depotInfo->location_id);

      if($q){
        $records = $records->where(function ($query) use ($q){
          $query->where('name_3', 'like', "%" . $q . "%")->orWhere("varname_3", 'like', "%" . $q . "%");
        })->get();
      }else{
        $records = $records->get();
      }

      $message = "";

      foreach($records as $record){
        $message .= $record->brgy . (($record->varname_3) ? " *" . $record->varname_3 . "*\u000A" : "\u000A");
      }

      $json = json_decode('{
        "messages": [
          {"text": "'.$message.'"}
        ]
      }');

      return response()->json($json);
    }

    public function botSelectCategory(Request $request){
      $data = $request->all();
      $q = trim($data["q"]);
      $parent_id = $data["parent_id"];

      $cat = DB::table("pabile_product_categories AS ppc")->select(DB::raw("ppc.*, (SELECT SUM(IF(pp.virtual_cost, 1, (SELECT COUNT(id) FROM pabile_inventories WHERE product_id = pp.id AND order_id IS NULL))) FROM pabile_products pp WHERE pp.category_id = ppc.id) AS bilang"))->where([['ppc.name', 'like', "%" . $q . "%"], ["ppc.parent_id", $parent_id]])
      ->having("bilang", "!=", 0)
      ->get();

      $json = json_decode('{
        
      }', true);

      if(count($cat) == 1){
        $json["set_attributes"] = [
          "u-cat-id" => $cat[0]->id,
          "u-cat-name" => $cat[0]->name
        ];
        $json["redirect_to_blocks"] = ["product select"];
      }elseif(count($cat) > 1){

        $json["redirect_to_blocks"] = ["multi category"];
      }else{
        $json["redirect_to_blocks"] = ["category no result"];
        
      }

      return response()->json($json);
    }

    public function getBotProductCategoryList(Request $request){
      $data = $request->all();
      $catId = $data["catId"];
      $q = (isset($data["q"])) ? trim($data["q"]) : false;

      $records = DB::table("pabile_product_categories as ppc")->where("parent_id", $catId)
      ->select(DB::raw('ppc.*, (SELECT SUM(IF(pp.virtual_cost, 1, (SELECT COUNT(id) FROM pabile_inventories WHERE product_id = pp.id AND order_id IS NULL))) FROM pabile_products pp WHERE pp.category_id = ppc.id) AS prodCount'))
      ->having("prodCount", "!=", 0);

      if($q){
        $records = $records->where('name', 'like', "%" . $q . "%")->get();
      }else{
        $records = $records->get();
      }

      $message = "";

      foreach($records as $record){
        $message .= $record->name . "\u000A";
      }

      $json = json_decode('{
        "messages": [
          {"text": "'.$message.'"}
        ]
      }');

      return response()->json($json);
    }

    public function botSelectMainCategory(Request $request){
      $data = $request->all();
      $q = trim($data["q"]);

      $main = DB::table("pabile_product_main_categories")->where('name', 'like', "%" . $q . "%")->get();

      $json = json_decode('{
        
      }', true);

      if(count($main) == 1){
        $json["set_attributes"] = [
          "u-main-cat-id" => $main[0]->id,
          "u-main-name" => $main[0]->name
        ];
        $json["redirect_to_blocks"] = ["category search"];
      }elseif(count($main) > 1){
        $json["set_attributes"] = [
          "u-main-cat-id" => $main[0]->id,
          "u-main-name" => $main[0]->name
        ];
        $json["redirect_to_blocks"] = ["multi main category"];
      }else{
        $json["redirect_to_blocks"] = ["main category no result"];
      }

      return response()->json($json);
    }

    public function getBotMainProductCategoryList(Request $request){
      $data = $request->all();
      $q = (isset($data["q"])) ? trim($data["q"]) : false;
      $records = DB::table("pabile_product_main_categories as ppmc")
      ->select(DB::raw("ppmc.*, (SELECT COUNT(ppc.id) FROM pabile_product_categories ppc WHERE ppc.parent_id = ppmc.id AND (SELECT COUNT(*) FROM pabile_products WHERE category_id = ppc.id) != 0) AS `catCount`"));

      if($q){
        $records = $records->where('name', 'like', "%" . $q . "%")->having("catCount", "!=", 0)->get();
      }else{
        $records = $records->having("catCount", "!=", 0)->get();
      }

      $message = "";

      foreach($records as $record){
        $message .= $record->name . "\u000A";
      }

      $json = json_decode('{
        "messages": [
          {"text": "'.$message.'"}
        ]
      }');

      return response()->json($json);
    }

    public function BotCheckCartItem(Request $request){
      $data = $request->all();
      $product_id = $data["product_id"];
      $messenger_uid = $data["messenger_uid"];
      $token = $data["token"];

      $json = json_decode('{
        
      }', true);

      $p = DB::table("pabile_products")->where("id", $product_id)->first();

      if(floatval($p->previous_price)){
        DB::table("pabile_temp_orders")->updateOrInsert(
          ["token" => $token, "product_id" => $product_id], ["token" => $token, "product_id" => $product_id, "qty" => 1]
        );

        $json["redirect_to_blocks"] = ["promo message", "item added"];
        //
        $sum = DB::table("pabile_temp_orders")->where("token", $token)->sum("qty");
        $json["set_attributes"] = [
          "u-cart-items" => $sum
        ];

        //totalItems
      }else{
        $query = DB::table("pabile_temp_orders")->where([["product_id", $product_id], ["token", $token]])->count();
        if($query){
          $json["redirect_to_blocks"] = ["item exist"];
        }else{
          $json["redirect_to_blocks"] = ["ask quantity"];
        }
      }

      
      
      return response()->json($json);
    }

    public function botCartClear(Request $request){
      $data = $request->all();
      $messenger_uid = $data["messenger_uid"];
      $token = $data["token"];

      DB::table("pabile_temp_orders")->where("token", $token)->delete();

      $json = json_decode('{
        "set_attributes":
          {
            "u-cart-items": 0
          }
      }');

      return response()->json($json);
    }

    public function botCartRemove(Request $request){
      $data = $request->all();
      $product_id = $data["product_id"];
      $messenger_uid = $data["messenger_uid"];
      $token = $data["token"];

      DB::table("pabile_temp_orders")->where([["product_id", $product_id], ["token", $token]])->delete();
      $c = DB::table("pabile_temp_orders")->where("token", $token)->sum("qty");
      $json = json_decode('{
        "set_attributes":
    {
      "u-cart-items": '.$c.'
    }
      }');

      return response()->json($json);
    }

    public function botCartItems(Request $request){
      $data = $request->all();
      $messenger_uid = $data["messenger_uid"];
      $token = $data["token"];
      $ready = (isset($data["ready"])) ? $data["ready"] : 0;
      $showCart = (isset($data["showCart"])) ? $data["showCart"] : 0;
      $items = [];
      $messages = [];
      $total = 0;
      $totalItems = 0;

      $recordsQ = DB::table("pabile_products as pp")->addBinding($token)
      ->whereIn('pp.id', function($query) use ($token){
        $query->select('product_id')
          ->from("pabile_temp_orders")
          ->where('token', $token);
      })
      ->select(DB::raw('pp.*, UNIX_TIMESTAMP(pp.updated_at) AS `updated_date`, (SELECT `qty` FROM pabile_temp_orders WHERE product_id = pp.id AND token = ?) AS `qty`, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 3 AND product_id = pp.id) AS `dimension`, (SELECT value FROM pabile_product_specs WHERE `key` = 10 AND product_id = pp.id) AS `type`, (SELECT value FROM pabile_product_specs WHERE `key` = 11 AND product_id = pp.id) AS `unit`, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT value FROM pabile_product_specs WHERE `key` = 4 AND product_id = pp.id) AS `manufacturer`, (SELECT photo FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'));

      if($ready){
        $sh = "";
        if($showCart){
          $sh = ',{
            "type": "show_block",
            "block_names": ["view cart"],
            "title": "View Cart"
          }';
        }
        $recordsQ = $recordsQ->count();
        if($recordsQ){
          $json = json_decode('{
            "messages": [
              {
                "attachment": {
                  "type": "template",
                  "payload": {
                    "template_type": "button",
                    "text": "Whenever you\'re ready for delivery of your order you may proceed to checkout",
                    "buttons": [
                      {
                        "type": "show_block",
                        "block_names": ["checkout"],
                        "title": "Checkout now"
                      }'.$sh.'
                    ]
                  }
                }
              }
            ]
          }', true);
        }
      }else{
        $recordsQ = $recordsQ->get();
        foreach($recordsQ as $r){
          $total += $r->qty * $r->price;
          $totalItems += $r->qty;
          $thumb = 'https://markloreto.xyz/botPhotoGallery/' . $r->id . "?t=" . $r->updated_date;
          $items[] = [
            "title" => "[ " . $r->qty . "x ] " . $r->name . (($r->brand) ? ", " . $r->brand : "") . (($r->weight) ? ", " . $r->weight : "") . (($r->color) ? ", " . $r->color : "") . (($r->flavor) ? ", " . $r->flavor : "") . (($r->size) ? ", " . $r->size : "") . (($r->size) ? ", " . $r->size : "") . (($r->manufacturer) ? ", " . $r->manufacturer : "") . (($r->dimension) ? ", " . $r->dimension : "") . (($r->type) ? ", " . $r->type : "") . (($r->unit) ? ", " . $r->unit : ""),
            "subtitle" => $r->description,
            "image_url" => $thumb,
            "buttons" => [
                [
                "set_attributes"=> 
                  [
                    "u-product-id" => $r->id,
                    "u-product-name" => $r->name
                  ],
                  "block_names" => ["ask item remove"],
                  "type" => "show_block",
                  "title" => "Remove from Cart"
                ]
            ]
          ];
        }
  
        $chunks = array_chunk($items, 10);
  
        foreach($chunks as $chunk){
          $messages[] = [
            "attachment" => [
              "type" => "template",
              "payload" => [
                "template_type" => "generic",
                "image_aspect_ratio" => "square",
                "elements" => $chunk
              ]
            ]
          ];
        }
  
        $json = json_decode('{
          "messages": []
        }', true);
  
        $json["messages"] = $messages;
        $json["set_attributes"] = [
          "u-cart-items" => $totalItems,
          "u-cart-total" => $total
        ];
        $json["redirect_to_blocks"] = ["after cart options"];
      }

      return response()->json($json);
    }

    public function botAddtoCart(Request $request){
      $data = $request->all();
      $product_id = $data["product_id"];
      $qty = $data["qty"];
      $messenger_uid = $data["messenger_uid"];
      $token = $data["token"];
      
      $p = DB::table("pabile_products")->select(DB::raw('*, UNIX_TIMESTAMP(updated_at) AS `updated_date`'))->where("id", $product_id)->first();

      $parameters = [
          'headings'       => [
              'en' => 'Someone is adding an item to their cart!'
          ],
          'contents'       => [
              'en' => $p->name
          ],
          'chrome_web_image' => "https://markloreto.xyz/botPhotoGallery/" . $product_id . "?t=" . $p->updated_date,
          'included_segments' => array('All'),
          'url' => "https://markloreto.xyz/botPhotoGallery/" . $product_id . "?t=" . $p->updated_date
      ];

      OneSignal::sendNotificationCustom($parameters);

      $query = DB::table("pabile_temp_orders")->where([["product_id", $product_id], ["token", $token]]);
      $c = clone $query;

      if($c->count()){
        $query = $query->get();
        DB::table('pabile_temp_orders')->where([["product_id", $product_id], ["token", $token]])
          ->update([ 
              'qty' => $qty
          ]);

          $sum = DB::table("pabile_temp_orders")->where("token", $token)->sum("qty");

          $json = json_decode('{
            "set_attributes": 
    {
      "u-cart-items": '.$sum.'
    },
            "redirect_to_blocks": ["item updated"]
          }');
      }else{
        DB::table("pabile_temp_orders")->insert(
          ["token" => $token, "product_id" => $product_id, "qty" => $qty]
        );

        $sum = DB::table("pabile_temp_orders")->where("token", $token)->sum("qty");

        $json = json_decode('{
          "set_attributes": 
    {
      "u-cart-items": '.$sum.'
    },
          "redirect_to_blocks": ["item added"]
        }');
      }

      return response()->json($json);
    }

    public function updateLatestDate(Request $request){
      $json = json_decode('{
        
      }', true);

      $json["set_attributes"] = [
        "u-updates-date" => Carbon::now()->toDateTimeString()
      ];

      return response()->json($json);

    }

    public function checkLatestDate(Request $request){
      $data = $request->all();
      $date = ($data["date"] == "null") ? Carbon::now() : $data["date"];

      $recordsQ = DB::table("pabile_products as pp")
      ->select(DB::raw('IF(pp.virtual_cost, 999, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL)) AS inventory'))
      ->having('inventory', '!=', 0)->where("pp.updated_at", ">=", $date)->get();

      $json = json_decode('{
        
      }', true);

      if(count($recordsQ)){
        $json["set_attributes"] = [
          "u-total-search-results" => count($recordsQ)
        ];
  
        $json["redirect_to_blocks"] = ["with latest updates"];
      }

      return response()->json($json);

    }

    public function botSearchProduct(Request $request){
      $data = $request->all();
      $q = $data["q"];
      $page = intval($data["page"]);
      $offset = $page * 10;
      $limit = 10;
      $ids = [];
      $items = [];
      $catId = (isset($data["catId"])) ? $data["catId"] : false;
      $latest = (isset($data["latest"])) ? $data["latest"] : false;
      $default = (isset($data["default"])) ? $data["default"] : false;
      $tryAnother = "Search for products";

      if(!$catId){
        $tags = DB::table("pabile_product_tags")->select("product_id")->where('name', 'like', "%" . $q . "%")->get();
        $specs = DB::table("pabile_product_specs")->select("product_id")->where('value', 'like', "%" . $q . "%")->get();
        foreach($tags as $tag){
            $ids[] = $tag->product_id;
        }

        foreach($specs as $spec){
            $ids[] = $spec->product_id;
        }
      }

      $recordsQ = DB::table("pabile_products as pp")
      ->select(DB::raw('pp.*, UNIX_TIMESTAMP(pp.updated_at) AS `updated_date`, IF(pp.virtual_cost, 999, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL)) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 3 AND product_id = pp.id) AS `dimension`, (SELECT value FROM pabile_product_specs WHERE `key` = 10 AND product_id = pp.id) AS `type`, (SELECT value FROM pabile_product_specs WHERE `key` = 11 AND product_id = pp.id) AS `unit`, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT value FROM pabile_product_specs WHERE `key` = 4 AND product_id = pp.id) AS `manufacturer`, (SELECT photo FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'))
      ->having('inventory', '!=', 0);

      if($catId){
        $recordsQ = $recordsQ->where("category_id", $catId);
        $tryAnother = "Main Category Search";
      }elseif($latest){
        $recordsQ = $recordsQ->where("pp.updated_at", ">=", $data["latestDate"]);
      }else{
        $recordsQ = $recordsQ->where('pp.name', 'like', "%" . $q . "%")->orWhere('description', 'like', "%" . $q . "%")->orWhereIn("pp.id", $ids);
      }

      $r = clone $recordsQ;
      $r2 = clone $recordsQ;

      $records = $r->forPage(($page+1), $limit)->get(); //$r->limit($limit)->offset($offset)->get();
      $recordsCount = count($records);
      $totalRecords = $r2->get();//->count();
      $totalRecords = count($totalRecords);
      $isThereNext = $totalRecords - ($offset + $recordsCount);

      if($totalRecords){
        foreach($records as $r){
          $thumb = 'https://markloreto.xyz/botPhotoGallery/' . $r->id . "?t=" . $r->updated_date;
          $items[] = [
            "title" => "[₱ " . $r->price . "] " . $r->name . (($r->brand) ? ", " . $r->brand : "") . (($r->weight) ? ", " . $r->weight : "") . (($r->color) ? ", " . $r->color : "") . (($r->flavor) ? ", " . $r->flavor : "") . (($r->size) ? ", " . $r->size : "") . (($r->manufacturer) ? ", " . $r->manufacturer : "") . (($r->dimension) ? ", " . $r->dimension : "") . (($r->type) ? ", " . $r->type : "") . (($r->unit) ? ", " . $r->unit : ""),
            "subtitle" => $r->description,
            "image_url" => $thumb,
            "buttons" => [
                [
                "set_attributes"=> 
                  [
                    "u-product-id" => $r->id,
                    "u-product-name" => $r->name
                  ],
                  "block_names" => ["check if item in cart"],
                  "type" => "show_block",
                  "title" => "Add to cart"
                ]
            ]
          ];
        }
  

        $next = "";
        if($isThereNext){
          $next = '{
            "type": "show_block",
            "block_names": ["search results"],
            "title": "Show more result"
          },';
        }

        if($totalRecords){
          $json = json_decode('{
            "set_attributes":
          {
            "u-search-page": "' . ($page + 1) . '",
            "u-total-search-results": '.$totalRecords.'
          },
            "messages": [
              {"text": "' . (($page === 0) ? $totalRecords . ' result found. ' : '') . 'showing record '. ($offset + 1) .' to ' . ($offset + $recordsCount) . (($page > 0) ? ' out of ' . $totalRecords : '') . '"},
              {
                "attachment":{
                  "type":"template",
                  "payload":{
                    "template_type":"generic",
                    "image_aspect_ratio": "square",
                    "elements":[]
                  }
                }
              },
              {
                "attachment": {
                  "type": "template",
                  "payload": {
                    "template_type": "button",
                    "text": "Select an item you want to add to your Cart or choose another option below",
                    "buttons": [
                      ' . $next . '
                      {
                        "type": "show_block",
                        "block_names": ["'.$tryAnother.'"],
                        "title": "try another search"
                      },
                      {
                        "type": "show_block",
                        "block_names": ["Main menu"],
                        "title": "Go back to main menu"
                      }
                    ]
                  }
                }
              }
            ]
          }', true);
          //u-updates-date
          $json["messages"][1]["attachment"]["payload"]["elements"] = $items;
        }else{
          if($latest){
            $json = json_decode('{
              "set_attributes":
                {
                  "u-total-search-results": 0
                }
            }');
          }else{
            $json = json_decode('{
              "messages": [
                {
                  "attachment": {
                    "type": "template",
                    "payload": {
                      "template_type": "button",
                      "text": "*' . $q  . '* not found 😥",
                      "buttons": [
                        {
                          "type": "show_block",
                          "block_names": ["'.$tryAnother.'"],
                          "title": "try another search"
                        },
                        {
                          "type": "show_block",
                          "block_names": ["Main menu"],
                          "title": "Go back to main menu"
                        }
                      ]
                    }
                  }
                }
              ]
            }', true);
          }
        }
      }else{
        if($latest){
          $json = json_decode('{
            "set_attributes":
              {
                "u-total-search-results": 0
              }
          }');
        }else{
          $json = json_decode('{
            "messages": [
              {
                "attachment": {
                  "type": "template",
                  "payload": {
                    "template_type": "button",
                    "text": "*' . $q  . '* not found 😥",
                    "buttons": [
                      {
                        "type": "show_block",
                        "block_names": ["'.$tryAnother.'"],
                        "title": "try another search"
                      },
                      {
                        "type": "show_block",
                        "block_names": ["Main menu"],
                        "title": "Go back to main menu"
                      }
                    ]
                  }
                }
              }
            ]
          }', true);
        }
        
      }

      if($default && !$totalRecords){
        $json = json_decode('{
          "redirect_to_blocks": ["cannot understand"]
        }');
        
      }

      return response()->json($json);
    }

    public function botItemSelected(Request $request){
      $data = $request->all();
      $itemName = $data["itemName"];
      $photo = str_replace("thumbnail", "photo", $data["photo"]);

      $parameters = [
          'headings'       => [
              'en' => 'Someone is adding an item to their cart!'
          ],
          'contents'       => [
              'en' => $itemName
          ],
          'chrome_web_image' => $photo,
          'included_segments' => array('All'),
          'url' => $photo
      ];

      OneSignal::sendNotificationCustom($parameters);

      return $this->sendResponse(1, 'botItemSelected');
    }

    public function botTimeNow(){
      $start = '06:00:00';
      $end   = '21:00:00';
      $now   = Carbon::now();
      $time  = $now->format('H:i:s');

      if ($time >= $start && $time <= $end) {
        
      }

      return $this->sendResponse($time, 'botTimeNow');
    }

    public function botRequest(Request $request){
      $data = $request->all();
      $keyword = $data["keyword"];
      $messengerId = $data["messenger_uid"];
      

      DB::table("pabile_no_results")->insert(
        ["keyword" => $keyword, "messenger_id" => $messengerId]
      );

      $count = DB::table("pabile_no_results")->where("keyword", $keyword)->count();
      $countMessage = "";
      if($count > 1){
        $countMessage = "This has been requested " . $count . " times";
      }

      OneSignal::sendNotificationToAll(
        "A client is requesting for '" . $keyword . "' "  .$countMessage,
        "https://dashboard.chatfuel.com/bot/5f1d5f37cf7d166801d21c5a/livechat?folder=all&conversationId=" . $messengerId, 
        null, 
        null, 
        null, 
        "Item Request!", 
        "facebook notification"
      );

      $json = json_decode('{
        "messages": [
          {
            "attachment": {
              "type": "template",
              "payload": {
                "template_type": "button",
                "text": "The item/s that you requested has been listed to our unavailable products list, please subsribe to receive new product updates",
                "buttons": [
                  {
                    "type": "show_block",
                    "block_names": ["OTN test"],
                    "title": "Subscribe"
                  },
                  {
                    "type": "show_block",
                    "block_names": ["Request product"],
                    "title": "New Request"
                  },
                  {
                    "type": "show_block",
                    "block_names": ["Main menu"],
                    "title": "Go to Main menu"
                  }
                ]
              }
            }
          }
        ]
      }', true);

      return response()->json($json);
    }

    public function botAddKeyword(Request $request){
      $data = $request->all();
      $keyword = $data["keyword"];
      $messengerId = $data["messenger_uid"];
      

      DB::table("pabile_no_results")->insert(
        ["keyword" => $keyword, "messenger_id" => $messengerId]
      );

      $count = DB::table("pabile_no_results")->where("keyword", $keyword)->count();
      $countMessage = "";
      if($count > 1){
        $countMessage = "This has been searched " . $count . " times";
      }

      OneSignal::sendNotificationToAll(
        "A client is searching for '" . $keyword . "' but with no result. "  .$countMessage,
        "https://dashboard.chatfuel.com/bot/5f1d5f37cf7d166801d21c5a/livechat?folder=all&conversationId=" . $messengerId, 
        null, 
        null, 
        null, 
        "Item not found!", 
        "facebook notification"
      );

      return $this->sendResponse(1, 'botAddKeyword');
    }

    public function botSummary(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $messengerId = $data["messenger_uid"];
      $clientId = $data["clientId"];
      $name = $data["name"];
      $mobile = $data["mobile"];
      $brgyId = $data["brgyId"];
      $brgyName = $data["brgyName"];
      $address = ($data["address"] == "null") ? null : ($data["address"]) ? $data["address"] : null;
      $depot = $data["depot"];
      $depotId = $data["depot_id"];
      $lat = ($data["lat"] == "null") ? null : ($data["lat"]) ? $data["lat"] : null;
      $lng = ($data["lng"] == "null") ? null : ($data["lng"]) ? $data["lng"] : null;

      $mobile = ltrim($mobile, '0');
      $mobile = ltrim($mobile, '+63');

      $Etinda = new EtindaController;
      $prefix = $Etinda->getMobilePrefix($mobile);

      $depotInfo = DB::table("pabile_depots")->where("id", $depotId)->first();
      $location = DB::table("locations")->select("province", "name_1", "name_2")->where("id_2", $depotInfo->location_id)->first();
      $items = DB::table("pabile_temp_orders")->where("token", $token)->get();
      //
      $realClientId = 0;

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $success = 1;

      if($hashedMessengerId != $token){
        $success = 0;
      }elseif (count($items) === 0) {
        $json = json_decode('{
          "redirect_to_blocks": ["noItemsInCart"]
        }', true);
      }
      else{

        if(intval($clientId) !== 0){
          //
          $realClientId = $clientId;

          $client = DB::table("pabile_clients")->where("mobile", $mobile)->first();
          DB::table('pabile_clients')->where("id", $realClientId)
          ->update([ 
              'name' => $name, 
              'brgy_id' => $brgyId,
              'lat' => $lat,
              'lng' => $lng,
              'mobile' => $mobile,
              'prefix_id' => $prefix,

          ]);
        }else{
          
  
          $isMobileExist = DB::table("pabile_clients")->where("mobile", $mobile)->count();
  
          if($isMobileExist){
              $client = DB::table("pabile_clients")->where("mobile", $mobile)->first();
              DB::table('pabile_clients')->where("id", $client->id)
              ->update([ 
                  'name' => $name, 
                  'brgy_id' => $brgyId,
                  'messenger_id' => $messengerId,
                  'lat' => $lat,
                  'lng' => $lng,
                  'prefix_id' => $prefix
              ]);
  
              $realClientId = $client->id;
          }else{
  
              $realClientId = DB::table('pabile_clients')->insertGetId(
                  ["name" => $name, "mobile" => $mobile, "brgy_id" => $brgyId, "prefix_id" => $prefix, "messenger_id" => $messengerId, "lat" => $lat, "lng" => $lng]
              );
          }
        }

        //Pending Orders Technique
        $pendingOrdersCount = DB::table("pabile_orders")->where([["client_id", $realClientId], ["status_id", 1]])->count();
        
        if($pendingOrdersCount > 3){
          $json = json_decode('{
            "redirect_to_blocks": ["pendingOrders"]
          }', true);
        }else{
          $total = 0;
          $orders = [];
          $ordersSave= [];
          $totalItems = 0;
          
          foreach($items as $item){
            $d = DB::table("pabile_products as pp")
            ->where("pp.id", $item->product_id)
            ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
            ->select(DB::raw('pp.*, ppc.name AS category_name, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'))
            ->first();

            $totalItems += $item->qty;
            $d->qty = $item->qty;
            $total += $item->qty * $d->price;

            $thumb = 'https://markloreto.xyz/pabile-photos/' . ltrim($d->thumbnail, 'pabile/');
            $orders[] = [
              "title" => $d->name,
              "subtitle" => $d->category_name,
              "quantity" => $item->qty,
              "price" => floatval($d->price),
              "currency" => "PHP",
              "image_url" => $thumb
            ];

            $ordersSave[] = [
              "productId" => $item->product_id,
              "qty" => $item->qty,
              "price" => $d->price
            ];
          }
          
          $request->request->add([
            'date' => date("o-m-d H:i:s"), 
            'schedule' => "Now", 
            "changeFor" => null, 
            "notes" => $address, 
            "items" => $ordersSave,
            "bot" => true,
            "origin" => "fb",
            "realClientId" => $realClientId,
            "depot_id" => $depotId,
            "order_id" => null
            ]);
          $orderId = $Etinda->submitOrder($request);

          
          $json = json_decode('{
            "set_attributes":
            {
              "u-status": "active",
              "u-id": '.$realClientId.',
              "u-cart-items": 0
            },
            "messages": [
              {
                "attachment": {
                  "type": "template",
                  "payload": { 
                    "template_type": "receipt",
                    "recipient_name": "' . $name . '",
                    "order_number": "'.$orderId.'",
                    "currency": "PHP",
                    "payment_method": "Cash on Delivery",
                    "order_url": "https://rockets.chatfuel.com/store?order_id=12345678901",
                    "timestamp": "' . time() . '",
                    "address": {
                      "street_1": "Barangay ' . $brgyName . ', ",
                      "street_2": "' .$address. '",
                      "city": "' . $depot . '",
                      "postal_code": "' . $depotInfo->location_id . '",
                      "state": "' .$location->province. '",
                      "country": "PH"
                    },
                    "summary": {
                      "subtotal": ' . $total . ',
                      "shipping_cost": 0,
                      "total_tax": 0,
                      "total_cost": ' . $total . '
                    },
                    "adjustments": [],
                    "elements": []
                  }
                }
              }
            ]
          }', true);

          $json["messages"][0]["attachment"]["payload"]["elements"] = $orders;

          DB::table('pabile_temp_orders')->where('token', $token)->delete();
          
          OneSignal::sendNotificationToAll(
            $name . " bought " .$totalItems . " items with the sum of ₱ " . $total,
            "http://localhost:4200/tabs/delivery", 
            [
                "type" => "fbOrder",
                "orderId" => $orderId,
                "clientId" => $realClientId
            ], 
            null, 
            null, 
            "May Bumili!", 
            "facebook notification"
          );
        }
      }

    return response()->json($json);

    }

    public function botSetAddress(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $messengerId = $data["messenger_uid"];
      $depotId = $data["depot_id"];
      $brgyId = $data["brgyId"];
      $brgyName = $data["brgyName"];
      $address = $data["address"];

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $success = 1;

      if($hashedMessengerId != $token){
        $success = 0;
      }else{
        $client = new Client([
          'headers' => [ 
              'Content-Type' => 'application/json'
            ]
        ]);
        $response = $client->post("https://api.chatfuel.com/bots/5f1d5f37cf7d166801d21c5a/users/" . $messengerId . "/send?chatfuel_token=mELtlMAHYqR0BvgEiMq8zVek3uYUK3OJMbtyrdNPTrQB9ndV0fM7lWTFZbM4MZvD&chatfuel_message_tag=POST_PURCHASE_UPDATE&chatfuel_block_name=removeLocation&u-address=".$address."&u-brgy-name=".$brgyName."&u-brgy_id=".$brgyId);
      }

      return $this->sendResponse($success, 'botSetAddress');
    }

    public function botSetLocation(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $messengerId = $data["messenger_uid"];
      $depotId = $data["depot_id"];
      $brgyId = $data["brgyId"];
      $brgyName = $data["brgyName"];
      $lat = $data["lat"];
      $lng = $data["lng"];

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $success = 1;

      if($hashedMessengerId != $token){
        $success = 0;
      }else{
        $client = new Client([
          'headers' => [ 
              'Content-Type' => 'application/json'
            ]
        ]);
        $response = $client->post("https://api.chatfuel.com/bots/5f1d5f37cf7d166801d21c5a/users/" . $messengerId . "/send?chatfuel_token=mELtlMAHYqR0BvgEiMq8zVek3uYUK3OJMbtyrdNPTrQB9ndV0fM7lWTFZbM4MZvD&chatfuel_message_tag=POST_PURCHASE_UPDATE&chatfuel_block_name=removeAddress&u-lat=".$lat."&u-lng=".$lng."&u-brgy-name=".$brgyName."&u-brgy_id=".$brgyId);
      }

      return $this->sendResponse($success, 'botSetLocation');
    }

    public function getBrgyInfo(Request $request){
      $data = $request->all();
      $brgyId = $data["brgyId"];
      $brgyInfo = DB::table("locations")->select(DB::raw("ST_AsGeoJSON(SHAPE) AS geo, region, province, name_2 AS municipal, name_3 AS brgy, varname_3, id_3 as brgyID"))->where('id_3', $brgyId)->first();

      return $this->sendResponse($brgyInfo, 'getBrgyInfo');
    }

    public function deleteTempOrders(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $messengerId = $data["messenger_uid"];

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      if($hashedMessengerId == $token){
        DB::table('pabile_temp_orders')->where('token', $token)->delete();
      }

      return $this->sendResponse("", 'deleteTempOrders');
    }

    public function getTempOrders(Request $request){
      $data = $request->all();
      $token = trim($data["token"]);
      $orders = [];

      $recs = DB::table("pabile_temp_orders")->where("token", $token)->get();
      foreach($recs as $rec){
        $d = DB::table("pabile_products as pp")
        ->where("pp.id", $rec->product_id)
        ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
        ->select(DB::raw('pp.*, ppc.name AS category_name, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'))
        ->first();

        $d->qty = $rec->qty;

        $orders[] = $d;

      }

      return $this->sendResponse($orders, 'getTempOrders');
    }

    public function fbOrder(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $items = $data["items"];
      $messengerId = $data["messenger_uid"];

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      DB::table('pabile_temp_orders')->where('token', $token)->delete();

      $isClientActive = DB::table('pabile_clients')->where('messenger_id', $messengerId)->count();

      $success = 1;
      $itemsCount = 0;
      $reason = "";

      foreach($items as $item){
        $itemsCount+= intval($item["qty"]);
      }

      if($hashedMessengerId != $token){
        $success = 0;
        $reason = "Authentication Failed";
      }elseif ($itemsCount > 25) {
        $success = 0;
        $reason = "Maximum cart items we can deliver is not more than 25";
      }elseif ($isClientActive && $itemsCount < 5) {
        $success = 0;
        $reason = "5 or more cart items is the minimum order";
      }
      else{

        foreach($items as $item){
          DB::table("pabile_temp_orders")->insert(
            ["token" => $token, "product_id" => $item["id"], "qty" => $item["qty"]]
          );
        }

        $client = new Client([
          'headers' => [ 
              'Content-Type' => 'application/json'
            ]
        ]);

        $start = '07:00:00';
        $end   = '22:00:00';
        $now   = Carbon::now();
        $time  = $now->format('H:i:s');

        if ($time >= $start && $time <= $end) {
          $response = $client->post("https://api.chatfuel.com/bots/5f1d5f37cf7d166801d21c5a/users/" . $messengerId . "/send?chatfuel_token=mELtlMAHYqR0BvgEiMq8zVek3uYUK3OJMbtyrdNPTrQB9ndV0fM7lWTFZbM4MZvD&chatfuel_message_tag=POST_PURCHASE_UPDATE&chatfuel_block_name=CartIn");
        }else{
          $response = $client->post("https://api.chatfuel.com/bots/5f1d5f37cf7d166801d21c5a/users/" . $messengerId . "/send?chatfuel_token=mELtlMAHYqR0BvgEiMq8zVek3uYUK3OJMbtyrdNPTrQB9ndV0fM7lWTFZbM4MZvD&chatfuel_message_tag=POST_PURCHASE_UPDATE&chatfuel_block_name=operatingHours");
        } 
      }

      return $this->sendResponse(["status" => $success, "reason" => $reason], 'fbOrder');
    }

    public function botGetToken(Request $request){
      $data = $request->all();
      $messengerId = $data["messenger user id"];
      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $json = json_decode('{
        "set_attributes":
          {
            "u-token": "' . $hashedMessengerId . '",
            "u-updates-date": "' . Carbon::now() . '"
          }
      }');

      return response()->json($json);
    }

    public function getBotCategoriesById(Request $request){
      $data = $request->all();
      $catId = $data["catId"];

      $records = DB::table("pabile_product_categories as ppc")->where("parent_id", $catId)
      ->select(DB::raw('ppc.*, (SELECT COUNT(*) FROM pabile_products WHERE ppc.id = category_id) as prodCount'))
      ->having("prodCount", "!=", 0)
      ->get();

      return $this->sendResponse($records, 'getCategoriesById');
    }

    public function getBotMainProductCategory(Request $request){
      $records = DB::table("pabile_product_main_categories as ppmc")
      ->select(DB::raw("ppmc.*, (SELECT COUNT(ppc.id) FROM pabile_product_categories ppc WHERE ppc.parent_id = ppmc.id AND (SELECT COUNT(*) FROM pabile_products WHERE category_id = ppc.id) != 0) AS `catCount`"))
      ->having("catCount", "!=", 0)
      ->get();
      return $this->sendResponse($records, 'getMainProductCategory');
    }

    public function botWelcome(Request $request){
        $data = $request->all();
        $language = $data["u-language"];

        if($language == "english"){
            $text = "Hi {{first name}}, \\n\\rthis is an english";
        }
        if($language == "tagalog"){
            $text = "Hi {{first name}}, \\n\\rtagalog ito!";
        }

        $json = json_decode('{
            "messages": [
              {
                "attachment": {
                  "type": "template",
                  "payload": {
                    "template_type": "button",
                    "text": "' . $text . '",
                    "buttons": [
                      {
                        "type": "show_block",
                        "block_names": ["name of block"],
                        "title": "Show Block"
                      },
                      {
                        "type": "web_url",
                        "url": "https://rockets.chatfuel.com",
                        "title": "Visit Website"
                      },
                      {
                        "url": "https://rockets.chatfuel.com/api/welcome",
                        "type":"json_plugin_url",
                        "title":"Postback"
                      }
                    ]
                  }
                }
              }
            ]
          }', true);

        return response()->json($json);
    }

    /* public function botMainProductCategories(Request $request){
        $data = $request->all();

        $records = DB::table("pabile_product_main_categories as ppmc")
        ->select(DB::raw("ppmc.*, (SELECT COUNT(*) FROM pabile_product_categories WHERE parent_id = ppmc.id) AS `catCount`"))
        ->get();

        $elements = [];

        foreach($records as $record){
            if($record->catCount){
                $elements[] = array(
                    "title" => $record->name,
                    "subtitle" => $record->catCount . " items"
                );
            }
        }

        return response()->json([
            "messages" => array(
                0 => array(
                    "attachment" => array(
                        "type" => "template",
                        "payload" => array(
                            "template_type" => "button",
                            "text" => "hello",
                            "buttons" => array(
                                0 => array(
                                    "type" => "web_url",
                                    "url" => "https://pabile-e.web.app/sample-page",
                                    "title" => "Visit",
                                    "webview_share_button" => "hide",
                                    "webview_height_ratio" => "tall",
                                    "messenger_extensions" => true
                                )
                            )
                        )
                    )
                )
            )
        ]);
    } */
}
