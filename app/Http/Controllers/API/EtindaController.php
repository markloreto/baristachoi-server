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
class EtindaController extends BaseController
{
    public function createProductCategory(Request $request){
        $data = $request->all();
        $name = $data["name"];
        $parent_id = $data["parent_id"];

        $seq = DB::table('pabile_product_categories')->max('id');

        DB::table('pabile_product_categories')->insert(
            ['name' => $name, 'sequence' => ($seq == null) ? 0 : $seq, 'parent_id' => $parent_id]
        );

        return $this->sendResponse($data, 'createProductCategory');
    }

    public function getProductCategory(){
        $records = DB::table("pabile_product_categories")->orderBy("sequence", "ASC")->get();
        return $this->sendResponse($records, 'getProductCategory');
    }

    public function getMainProductCategory(){
        $records = DB::table("pabile_product_main_categories")->get();
        return $this->sendResponse($records, 'getMainProductCategory');
    }

    public function getCategories(){
        $mainCategories = DB::table("pabile_product_main_categories")->get();

        foreach($mainCategories as $mainCategory){
            $category = DB::table("pabile_product_categories as pc")
            ->select(DB::raw('pc.*, (SELECT COUNT(*) FROM pabile_products WHERE category_id = pc.id) AS numberOfProducts'))
            ->where('parent_id', $mainCategory->id)->get();
            $mainCategory->categories = $category;
        }

        return $this->sendResponse($mainCategories, 'getCategories');
    }

    public function getSpecKeys(){
        $records = DB::table("pabile_spec_keys")->get();
        return $this->sendResponse($records, 'getMainProductCategory');
    }

    public function createSpecKeys(Request $request){
        $data = $request->all();
        $name = $data["name"];

        $id = DB::table('pabile_spec_keys')->insertGetId(
            ['name' => $name]
        );

        return $this->sendResponse($id, 'createSpecKeys');
    }

    public function getProductTags(Request $request){
        $data = $request->all();
        $q = $data["q"];
        $records = DB::table("pabile_product_tags")->where('name', 'like', "%" . $q . "%")->get();
        return $this->sendResponse($records, 'getProductTags');
    }

    public function createNewProduct(Request $request){
        $data = $request->all();
        $name = $data["name"];
        $mainCategory = $data["mainCategory"];
        $category = $data["category"];
        $enabled = $data["enabled"];
        $description = $data["description"];
        $photos = $data["photos"];
        $specs = $data["specs"];
        $tags = $data["tags"];
        $primaryPhoto = intval($data["primaryPhoto"]);
        $barcode = $data["barcode"];
        $price = $data["price"];
        $modify = $data["modify"];

        $seq = DB::table('pabile_products')->max('id');

        if($modify){
            $id = $modify;
            DB::table("pabile_products")->where('id', $id)
            ->update(['name' => $name, 'category_id' => $category, 'description' => $description, 'enabled' => $enabled, 'barcode' => $barcode, 'price' => $price]);
            
            $photosPrevious = DB::table("pabile_product_photos")->where("product_id", $id)->get();

            foreach($photosPrevious as $photo){
                Storage::disk('local')->delete([$photo->photo, $photo->thumbnail]);
            }

            DB::table('pabile_product_photos')->where('product_id', $id)->delete();
            DB::table('pabile_product_specs')->where('product_id', $id)->delete();
            DB::table('pabile_product_tags')->where('product_id', $id)->delete();
        
        }else{
            $id = DB::table("pabile_products")->insertGetId(
                ["name" => $name, "category_id" => $category, "description" => $description, "sequence" => $seq, "enabled" => $enabled, "barcode" => $barcode, "price" => $price]
            );
        }

        $milliseconds = round(microtime(true) * 1000);
        foreach($photos as $photo){
            $photoLink = $milliseconds + $photo["index"];

            //$image = preg_replace('/^data:image\/\w+;base64,/', '', $photo["photo"]);
            $image = str_replace('data:image/*;base64,', '', $photo["photo"]);
            $image = str_replace('data:image/png;base64,', '', $image);
            $image = str_replace('data:image/jpeg;base64,', '', $image);
            $image = str_replace(' ', '+', $image);

            //$imageThumb = preg_replace('/^data:image\/\w+;base64,/', '', $photo["thumbnail"]);
            $imageThumb = str_replace('data:image/*;base64,', '', $photo["thumbnail"]);
            $imageThumb = str_replace('data:image/png;base64,', '', $imageThumb);
            $imageThumb = str_replace('data:image/jpeg;base64,', '', $imageThumb);
            $imageThumb = str_replace(' ', '+', $imageThumb);

            Storage::disk('local')->put("pabile/photo" . $photoLink . ".jpg", base64_decode($image));
            Storage::disk('local')->put("pabile/thumbnail" . $photoLink . ".jpg", base64_decode($imageThumb));
            
            DB::table('pabile_product_photos')->insert(
                ["photo" => "pabile/photo" . $photoLink . ".jpg", "thumbnail" => "pabile/thumbnail" . $photoLink . ".jpg", "primary" => ($primaryPhoto === $photo["index"]) ? 1 : 0, "product_id" => $id, "index" => $photo["index"]]
            );
        }

        foreach($specs as $spec){
            if($spec["key"]["key"] && $spec["value"]){
                DB::table('pabile_product_specs')->insert(
                    ["product_id" => $id, "key" => $spec["key"]["key"], "value" => $spec["value"]]
                );
            }  
        }

        foreach($tags as $tag){
            DB::table('pabile_product_tags')->insert(
                ["product_id" => $id, "name" => $tag["value"]]
            );
        }

        return $this->sendResponse($id, 'createNewProduct');
    }

    public function getProducts(Request $request){
        $data = $request->all();
        $categoryId = $data["categoryId"];
        
        $records = DB::table("pabile_products as pp")->select(DB::raw('pp.*, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL AND pi.inventory_out_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`'))->where("pp.category_id", $categoryId)->get();
        return $this->sendResponse($records, 'getProducts');
    }

    public function checkBarcode(Request $request){
        $data = $request->all();
        $barcode = $data["barcode"];
        $record = DB::table("pabile_products")->where("barcode", $barcode)->get();
        return $this->sendResponse($record, 'checkBarcode');
    }

    public function searchProducts(Request $request){
        $data = $request->all();
        $q = $data["q"];
        $records = DB::table("pabile_products as pp")
        ->where('pp.name', 'like', "%" . $q . "%")
        ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
        ->select(DB::raw('pp.*, ppc.name AS category_name, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL AND pi.inventory_out_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'))
        ->limit(10)
        ->get();

        return $this->sendResponse($records, 'searchProducts');
    }

    public function purchase(Request $request){
        $data = $request->all();
        $date = $data["date"];
        $models = $data["models"];
        $depot_id = $data["depot_id"];

        $id = DB::table("pabile_purchases")->insertGetId(
            ["created_at" => $date, "depot_id" => $depot_id]
        );

        foreach($models as $model){
            for ($x = 0; $x < $model["qty"]; $x++) {
                DB::table("pabile_inventories")->insert(
                    ["product_id" => $model["id"], "cost" => $model["cost"], "price" => null/* $model["price"] */, "purchase_id" => $id]
                );
            }
        }

        return $this->sendResponse("Success", 'searchProducts');
    }

    public function getProductDetails(Request $request){
        $data = $request->all();
        $productId = $data["productId"];

        $product = DB::table("pabile_products as pp")->select(DB::raw('pp.*, (SELECT parent_id FROM pabile_product_categories WHERE id = pp.category_id) AS parent_id, (SELECT name FROM pabile_product_categories WHERE id = pp.category_id) AS category_name'))->where("pp.id", $productId)->first();
        $specs = DB::table("pabile_product_specs as pps")->select(DB::raw('pps.*, (SELECT name FROM pabile_spec_keys WHERE id = pps.key) AS keyName'))->where("pps.product_id", $productId)->get();

        $photos = DB::table("pabile_product_photos")->where("product_id", $productId)->get();
        foreach($photos as $photo){
            $base64 = base64_encode(Storage::disk('local')->get($photo->thumbnail));
            $photo->thumbnailb64 = $base64;

            $base64_2 = base64_encode(Storage::disk('local')->get($photo->photo));
            $photo->photob64 = $base64_2;
        }

        $tags = DB::table("pabile_product_tags")->where("product_id", $productId)->get();


        return $this->sendResponse(array("product" => $product, "specs" => $specs, "photos" => $photos, "tags" => $tags), 'getProductDetails');
    }

    public function pabileBrgyList(Request $request){
        $data = $request->all();
        $depotId = $data["depotId"];

        $depot = DB::table("pabile_depots")->where("id", $depotId)->first();
        $brgys = DB::table("locations")->select('id_3', 'name_3', 'varname_3')->where("id_2", $depot->location_id)->get();
        return $this->sendResponse($brgys, 'pabileBrgyList');
    }

    public function addClient(Request $request){
        $data = $request->all();
        $name = $data["name"];
        $mobile = $data["mobile"];
        $brgyId = $data["brgyId"];

        $isMobileExist = DB::table("pabile_clients")->where("mobile", $mobile)->count();

        if($isMobileExist){
            return $this->sendResponse(false, 'addClient');
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

            $id = DB::table('pabile_clients')->insertGetId(
                ["name" => $name, "mobile" => $mobile, "brgy_id" => $brgyId, "prefix_id" => $v]
            );
            return $this->sendResponse($id, 'addClient');
        }
    }

    public function searchClients(Request $request){
        $data = $request->all();
        $q = $data["q"];

        $records = DB::table("pabile_clients as pc")
        ->join('locations as l', 'l.id_3', '=', 'pc.brgy_id')
        ->select("pc.*", "l.name_3", "l.varname_3")
        ->where('name', 'like', "%" . $q . "%")
        ->orWhere('mobile', 'like', "%" . $q . "%")
        ->get();
        return $this->sendResponse($records, 'getProductTags');
    }

    public function submitOrder(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];
        $date = $data["date"];
        $schedule = $data["schedule"];
        $changeFor = $data["changeFor"];
        $notes = $data["notes"];
        $clientId = $data["clientId"];
        $items = $data["items"];

        $id = DB::table('pabile_orders')->insertGetId(
            ["client_id" => $clientId, "date" => $date, "schedule" => $schedule, "changeFor" => $changeFor, "notes" => $notes, "created_at" => Carbon::today(), "status_id" => 1, "origin" => "pos"]
        );

        foreach($items as $item){
            //DB::statement("UPDATE pabile_inventories SET price = " . $item["price"] . ", order_id = " . $id . " WHERE product_id = " . $item["productId"] .  " AND (order_id IS NULL AND inventory_out_id IS NULL) ORDER BY id ASC LIMIT " . $item["qty"]);
            DB::table("pabile_inventories")->whereRaw('product_id = ? AND (order_id IS NULL AND inventory_out_id IS NULL)', [$item["productId"]])
            ->orderBy('id', 'asc')
            ->limit($item["qty"])
            ->update([ 
                'price' => $item["price"], 
                'order_id' => $id
             ]);
        }

        return $this->sendResponse($id, 'submitOrder');
    }

    public function deliveries(Request $request){
        $data = $request->all();
        $records = DB::table("pabile_orders as po")
        ->select(DB::raw('po.*, pos.name, (SELECT name FROM pabile_riders WHERE id = po.rider_id) AS riderName, (SELECT nickName FROM pabile_riders WHERE id = po.rider_id) AS riderNickName, (SELECT SUM(price) FROM pabile_inventories WHERE order_id = po.id) AS `grandTotal`'))
        ->where([["status_id", "!=", 5], ["status_id", "!=", 6]])
        ->join('pabile_order_status as pos', 'pos.id', '=', 'po.status_id')
        ->orderBy("po.id", "desc")

        ->get()->groupBy("name");

        foreach($records as $record){
            foreach($record as $rec){
                if($rec->client_id){
                    $client = DB::table("pabile_clients as pc")
                    ->join('locations as l', 'l.id_3', '=', 'pc.brgy_id')
                    ->select(DB::raw('pc.*, l.name_3, l.varname_3, (SELECT `network` FROM pabile_mobile_prefixes WHERE id = pc.prefix_id) AS mobile_network'))
                    ->where("pc.id", $rec->client_id)->first();
                    $rec->client = $client;
                }else{
                    $rec->client = null;
                }
            }
        }

        return $this->sendResponse($records, 'deliveries');
    }

    public function getOrderItems(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        $items = DB::table("pabile_inventories as pi")->where("pi.order_id", $orderId)
        ->select(DB::raw('pp.*, COUNT(pi.product_id) AS qty, AVG(pi.price) AS `var_price`, (COUNT(pi.product_id) * AVG(pi.price)) AS subTotal, (SELECT photo FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `Photo`, (SELECT name FROM pabile_product_categories WHERE id = pp.category_id) AS categoryName'))
        ->join('pabile_products as pp', 'pi.product_id', '=', 'pp.id')
        ->groupBy("pi.product_id", "pi.price")
        ->get();

        foreach($items as $item){
            $specs = DB::table("pabile_product_specs as pps")
            ->select("pps.*", "psk.name")
            ->join('pabile_spec_keys as psk', 'pps.key', '=', 'psk.id')
            ->where("pps.product_id", $item->id)
            ->get();

            $item->specs = $specs;
        }

        return $this->sendResponse($items, 'getOrderItems');
    }

    public function voidOrder(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        DB::table('pabile_orders')->where('id', $orderId)->delete();
        DB::table('pabile_inventories')->where("order_id", $orderId)
        ->update([ 
            'price' => null, 
            'order_id' => null
        ]);

        return $this->sendResponse("", 'voidOrder');
    }

    public function processOrder(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        DB::table('pabile_orders')->where("id", $orderId)
        ->update([ 
            'status_id' => 2
        ]);

        return $this->sendResponse("", 'processOrder');
    }

    public function deliveredOrder(Request $request){
        $data = $request->all();
        $delivereds = $data["delivereds"];

        foreach($delivereds as $delivered){
            DB::table('pabile_orders')->whereIn("id", $delivered["orderId"])
            ->update([ 
                'status_id' => 4
            ]);

            $order = DB::table('pabile_orders')->select("client_id")->where("id", $orderId)->first();

            DB::table('pabile_clients')->whereIn("id", $order->client_id)
            ->update([ 
                'lat' => $delivered["lat"],
                'lng' => $delivered["lng"]
            ]);
        }

        return $this->sendResponse($delivereds, 'deliveredOrder');
    }

    public function completeOrder(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        DB::table('pabile_orders')->where("id", $orderId)
        ->update([ 
            'status_id' => 6
        ]);

        return $this->sendResponse("", 'completeOrder');
    }

    public function getRiders(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];

        $records = DB::table("pabile_riders")->where("depot_id", $depot_id)->get();

        return $this->sendResponse($records, 'getRiders');
    }

    public function setRider(Request $request){
        $data = $request->all();
        $riderId = $data["riderId"];
        $orderId = $data["orderId"];

        DB::table('pabile_orders')->where("id", $orderId)
        ->update([ 
            'rider_id' => $riderId,
            'status_id' => 3
        ]);

        return $this->sendResponse("", 'setRider');
    }

    public function updateMobilePrefix(){
        $records = DB::table("pabile_clients")->where("prefix_id", null)->get();

        foreach($records as $record){
            $uMobilePrefix = substr($record->mobile, 0, 4);

            $prefixRec = DB::table("pabile_mobile_prefixes")->where("prefix", $uMobilePrefix)->first();
            
            if($prefixRec == null){
                $uMobilePrefix = substr($record->mobile, 0, 3);

                $prefixRec = DB::table("pabile_mobile_prefixes")->where("prefix", $uMobilePrefix)->first();
            }

            if($prefixRec == null){
                $v = null;
            }else{
                $v = $prefixRec->id;
            }

            DB::table('pabile_clients')->where("id", $record->id)
            ->update([ 
                'prefix_id' => $v
            ]);
        }

        return $this->sendResponse($records, 'updateMobilePrefix');
    }
}
