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

use App\Exports\MachinesExport;
use App\Exports\CallsheetsExport;
use App\Exports\ClientsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;

//
class BotController extends BaseController
{
    //BOT
  public function botSearchProduct(Request $request){
    $data = $request->all();
    $q = $data["q"];
    $page = intval($data["page"]);
    $offset = $page * 10;
    $limit = 10;
    $ids = [];
    $items = [];

    $tags = DB::table("pabile_product_tags")->select("product_id")->where('name', 'like', "%" . $q . "%")->get();
    $specs = DB::table("pabile_product_specs")->select("product_id")->where('value', 'like', "%" . $q . "%")->get();
    foreach($tags as $tag){
        $ids[] = $tag->product_id;
    }

    foreach($specs as $spec){
        $ids[] = $spec->product_id;
    }

    $recordsQ = DB::table("pabile_products as pp")
    ->where('pp.name', 'like', "%" . $q . "%")->orWhere('description', 'like', "%" . $q . "%")->orWhereIn("pp.id", $ids)
    ->select(DB::raw('pp.*, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 3 AND product_id = pp.id) AS `dimension`, (SELECT value FROM pabile_product_specs WHERE `key` = 10 AND product_id = pp.id) AS `type`, (SELECT value FROM pabile_product_specs WHERE `key` = 11 AND product_id = pp.id) AS `unit`, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT value FROM pabile_product_specs WHERE `key` = 4 AND product_id = pp.id) AS `manufacturer`, (SELECT photo FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'));

    $r = clone $recordsQ;
    $r2 = clone $recordsQ;
    $r3 = clone $recordsQ;

    $records = $r->limit($limit)->offset($offset)->get();
    $recordsCount = count($records);
    $totalRecords = $r2->count();
    $isThereNext = $r3->limit($limit)->offset((($page + 1) * $limit))->count();
    

    foreach($records as $r){
      $thumb = 'https://markloreto.xyz/pabile-photos/' . str_replace("pabile/", "", $r->thumbnail);
      $items[] = [
        "title" => $r->name . (($r->brand) ? ", " . $r->brand : "") . (($r->weight) ? ", " . $r->weight : "") . (($r->color) ? ", " . $r->color : "") . (($r->flavor) ? ", " . $r->flavor : "") . (($r->size) ? ", " . $r->size : "") . (($r->size) ? ", " . $r->size : "") . (($r->manufacturer) ? ", " . $r->manufacturer : "") . (($r->dimension) ? ", " . $r->dimension : "") . (($r->type) ? ", " . $r->type : "") . (($r->unit) ? ", " . $r->unit : ""),
        "subtitle" => $r->description,
        "image_url" => $thumb,
        "buttons" => [
            [
            "block_names" => ["product selected"],
            "type" => "show_block",
            "title" => "₱ " . $r->price
            ]
        ]
      ];
    }

    $next = "";
    if($isThereNext){
      $next = ',
      {
        "attachment": {
          "type": "template",
          "payload": {
            "template_type": "button",
            "text": "Show more result for `' . $q . '`",
            "buttons": [
              {
                "type": "show_block",
                "block_names": ["search results"],
                "title": "Show Block"
              }
            ]
          }
        }
      }
      ';
    }

    $json = json_decode('{
      "set_attributes":
    {
      "u-search-page": "' . ($page + 1) . '"
    },
      "messages": [
         {"text": "' . (($page === 0) ? $totalRecords . ' search result found. ' : '') . 'showing record '. ($offset + 1) .' to ' . ($offset + $recordsCount) . (($page > 0) ? ' out of ' . $totalRecords : '') . '"},
         {"text": "Select a product you want to add to the cart"},
         {
           "attachment":{
             "type":"template",
             "payload":{
               "template_type":"generic",
               "image_aspect_ratio": "square",
               "elements":[]
             }
           }
         }' . $next . '
       ]
     }', true);

     $json["messages"][2]["attachment"]["payload"]["elements"] = $items;

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
            "redirect_to_blocks": ["tooManyPending"]
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
            "realClientId" => $realClientId
            ]);
          $orderId = $Etinda->submitOrder($request);

          $json = json_decode('{
            "set_attributes":
            {
              "u-status": "active",
              "u-id": '.$realClientId.'
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

        $start = '06:00:00';
        $end   = '21:00:00';
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
            "u-token": "' . $hashedMessengerId . '"
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
