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

    public function botSummary(Request $request){
      $data = $request->all();
      $token = $data["token"];
      $messengerId = $data["messenger_uid"];
      $clientId = $data["client_id"];
      $name = $data["name"];
      $mobile = $data["mobile"];
      $brgyId = $data["brgyId"];
      $brgyName = $data["brgyName"];
      $address = ($data["address"]) ? $data["address"] : "";
      $depot = $data["depot"];
      //
      $realClientId = 0;

      if(intval($clientId) !== 0){
        //
        $realClientId = $clientId;
      }else{
        $mobile = ltrim($mobile, '0');
        $mobile = ltrim($mobile, '+63');

        $isMobileExist = DB::table("pabile_clients")->where("mobile", $mobile)->count();

        if($isMobileExist){
            $client = DB::table("pabile_clients")->where("mobile", $mobile)->first();
            DB::table('pabile_clients')->where("id", $client->id)
            ->update([ 
                'name' => $name, 
                'brgy_id' => $brgyId,
                'messenger_id' => $messengerId
            ]);

            $realClientId = $client->id;
        }else{
            //Network Prefix
            $uMobilePrefix = substr($mobile, 0, 4);

            $prefixRec = DB::table("pabile_mobile_prefixes")->where("prefix", $uMobilePrefix)->first();
            
            if($prefixRec == null){
                $uMobilePrefix = substr($mobile, 0, 3);

                $prefixRec = DB::table("pabile_mobile_prefixes")->where("prefix", $uMobilePrefix)->first();
            }

            if($prefixRec == null){
                $v = null;
            }else{
                $v = $prefixRec->id;
            }
            //

            $realClientId = DB::table('pabile_clients')->insertGetId(
                ["name" => $name, "mobile" => $mobile, "brgy_id" => $brgyId, "prefix_id" => $v, "messenger_id" => $messengerId]
            );
        }
      }

      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $success = 1;

      if($hashedMessengerId != $token){
        $success = 0;
      }else{
        $st = ($address) ? "Barangay " . $brgyName . ", " . $address : "Barangay " . $brgyName;

        $total = 0;
        $orders = [];
        $items = DB::table("pabile_temp_orders")->where("token", $token)->get();
        foreach($items as $item){
          $d = DB::table("pabile_products as pp")
          ->where("pp.id", $item->product_id)
          ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
          ->select(DB::raw('pp.*, ppc.name AS category_name, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'))
          ->first();

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
        }

        $json = json_decode('{
          "messages": [
            {
              "attachment": {
                "type": "template",
                "payload": { 
                  "template_type": "receipt",
                  "recipient_name": "' . $name . '",
                  "order_number": "-for confirmation-",
                  "currency": "PHP",
                  "payment_method": "Cash on Delivery",
                  "order_url": "https://rockets.chatfuel.com/store?order_id=12345678901",
                  "timestamp": "' . time() . '",
                  "address": {
                    "street_1": "Barangay ' . $brgyName . '",
                    "street_2": $address,
                    "city": "' . $depot . '",
                    "postal_code": "",
                    "state": "' . $depot . '",
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

      $success = 1;
      $itemsCount = 0;

      foreach($items as $item){
        $itemsCount+= intval($item["qty"]);
      }

      if($itemsCount > 100 || $hashedMessengerId != $token){
        $success = 0;
      }else{

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

        $response = $client->post("https://api.chatfuel.com/bots/5f1d5f37cf7d166801d21c5a/users/" . $messengerId . "/send?chatfuel_token=mELtlMAHYqR0BvgEiMq8zVek3uYUK3OJMbtyrdNPTrQB9ndV0fM7lWTFZbM4MZvD&chatfuel_message_tag=POST_PURCHASE_UPDATE&chatfuel_block_name=CartIn");
      }

      return $this->sendResponse($success, 'fbOrder');
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
