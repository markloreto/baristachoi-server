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
    public function botWelcome(Request $request){
        $data = $request->all();
        $language = $data["u-language"];

        if($language == "english"){
            $text = "Hi {{first name}}, \n\r\n\r
            this is an english
            ";
        }
        if($language == "tagalog"){
            $text = "Hi {{first name}}, \n\r\n\r
            tagalog ito!
            ";
        }

        return response()->json(json_decode('
            {
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
                            "title": "Show Block 1"
                          },
                          {
                            "type": "show_block",
                            "block_names": ["name of block"],
                            "title": "Show Block 2"
                          }
                        ]
                      }
                    }
                  }
                ]
              }
        ', true));
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
