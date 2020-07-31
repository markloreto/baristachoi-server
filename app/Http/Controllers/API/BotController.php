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
    public function botGetToken(Request $request){
      $data = $request->all();
      $messengerId = $data["messenger user id"];
      $hashedMessengerId = hash_hmac('ripemd160', $messengerId, 'chrono');

      $json = json_decode('{
        "set_attributes":
          {
            "u-token": " ' . $hashedMessengerId . ' "
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
      ->select(DB::raw("ppmc.*, (SELECT COUNT(ppc.id) FROM pabile_product_categories ppc WHERE ppc.parent_id = ppmc.id HAVING (SELECT COUNT(*) FROM pabile_products WHERE category_id = ppc.id) != 0) AS `catCount`"))
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
