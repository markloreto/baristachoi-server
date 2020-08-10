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
    public function getProductAvgCost(Request $request){
        $data = $request->all();
        $product_id = $data["product_id"];
        $record = DB::table("pabile_inventories")->where("product_id", $product_id)->avg('cost');

        return $this->sendResponse($record, 'getProductAvgCost');
    }

    public function getFbOrders(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        $items = DB::table("pabile_fb_orders as pi")->where("pi.order_id", $orderId)
        ->select(DB::raw('pp.*, qty, (SELECT photo FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `Photo`, (SELECT name FROM pabile_product_categories WHERE id = pp.category_id) AS categoryName'))
        ->join('pabile_products as pp', 'pi.product_id', '=', 'pp.id')
        ->get();

        foreach($items as $item){
            $specs = DB::table("pabile_product_specs as pps")
            ->select("pps.*", "psk.name")
            ->join('pabile_spec_keys as psk', 'pps.key', '=', 'psk.id')
            ->where("pps.product_id", $item->id)
            ->get();

            $item->inventory = $this->getFbInventoryByProductId($item->id);

            $item->specs = $specs;
        }

        return $this->sendResponse($items, 'getOrderItems');

        foreach($records as $record){
            $record->inventory = $this->getFbInventoryByProductId($record->id);
        }

        return $this->sendResponse($records, 'getFbOrders');
    }

    public function getFbInventoryByProductId($productId){
        //(SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory
        $inventory = DB::table("pabile_inventories")->where("product_id", $productId)->whereNull("order_id")->count();
        return $inventory;
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

    public function searchProducts(Request $request){
        $data = $request->all();
        $q = $data["q"];
        $pid = (isset($data["pid"])) ? $data["pid"] : null;
        $returnAsData = (isset($data["returnAsData"])) ? true : false;
        $ids = [];

        if($pid){
            $where = [["pp.id", $pid]];
        }else{
            $where = [['pp.name', 'like', "%" . $q . "%"]];
            $tags = DB::table("pabile_product_tags")->select("product_id")->where('name', 'like', "%" . $q . "%")->get();
            $specs = DB::table("pabile_product_specs")->select("product_id")->where('value', 'like', "%" . $q . "%")->get();
            foreach($tags as $tag){
                $ids[] = $tag->product_id;
            }

            foreach($specs as $spec){
                $ids[] = $spec->product_id;
            }
        }

        $records = DB::table("pabile_products as pp")
        ->where($where)
        ->join('pabile_product_categories AS ppc', 'pp.category_id', '=', 'ppc.id')
        ->select(DB::raw('pp.*, ppc.name AS category_name, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT value FROM pabile_product_specs WHERE `key` = 5 AND product_id = pp.id) AS `flavor`, (SELECT value FROM pabile_product_specs WHERE `key` = 9 AND product_id = pp.id) AS `size`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`'));
        /* ->limit(10);
        ->get(); */

        if($returnAsData){
            return $records->limit(20)->get();
        }else{
            if(!$pid){
                $records = $records->orWhere('description', 'like', "%" . $q . "%");
            }
            
            return $this->sendResponse($records->orWhereIn("pp.id", $ids)->limit(50)->get(), 'searchProducts');
        }
    }

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
        $records = DB::table("pabile_product_main_categories as ppmc")
        ->select(DB::raw("ppmc.*, (SELECT COUNT(*) FROM pabile_product_categories WHERE parent_id = ppmc.id) AS `catCount`"))
        ->get();
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
        $isDesktop = (isset($data["isDesktop"])) ? $data["isDesktop"] : false;

        $seq = DB::table('pabile_products')->max('id');

        if($modify){
            $id = $modify;
            DB::table("pabile_products")->where('id', $id)
            ->update(['name' => $name, 'category_id' => $category, 'description' => $description, 'enabled' => $enabled, 'barcode' => $barcode, 'price' => $price]);
            
            if(!$isDesktop){
                $photosPrevious = DB::table("pabile_product_photos")->where("product_id", $id)->get();

                foreach($photosPrevious as $photo){
                    Storage::disk('local')->delete([$photo->photo, $photo->thumbnail]);
                }

                DB::table('pabile_product_photos')->where('product_id', $id)->delete();
            }
            
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
        $categoryId = intval($data["categoryId"]);
        
        $records = DB::table("pabile_products as pp")
        ->select(DB::raw('pp.*, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL) AS inventory, (SELECT value FROM pabile_product_specs WHERE `key` = 6 AND product_id = pp.id) AS brand, (SELECT value FROM pabile_product_specs WHERE `key` = 1 AND product_id = pp.id) AS weight, (SELECT value FROM pabile_product_specs WHERE `key` = 2 AND product_id = pp.id) AS `color`, (SELECT thumbnail FROM pabile_product_photos WHERE product_id = pp.id AND `primary` = 1) AS `thumbnail`, (SELECT COUNT(*) FROM pabile_product_photos WHERE product_id = pp.id) AS `numPhotos`'))->where("pp.category_id", $categoryId);
        if($categoryId != 0){
            $records->where("pp.category_id", $categoryId)->get();
        }else{
            $records->having('numPhotos', '=', 0)->get();
        }
        //->having('mySold', '!=', 0)
        
        return $this->sendResponse($records, 'getProducts');
    }

    public function checkBarcode(Request $request){
        $data = $request->all();
        $barcode = $data["barcode"];
        $record = DB::table("pabile_products")->where("barcode", $barcode)->get();
        return $this->sendResponse($record, 'checkBarcode');
    }

    public function purchase(Request $request){
        $data = $request->all();
        $date = $data["date"];
        $models = $data["models"];
        $depot_id = $data["depot_id"];

        foreach($models as $model){
            $id = DB::table("pabile_purchases")->insertGetId(
                ["created_at" => $date, "depot_id" => $depot_id]
            );

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
        $isDesktop = (isset($data["isDesktop"])) ? $data["isDesktop"] : false;

        $product = DB::table("pabile_products as pp")->select(DB::raw('pp.*, (SELECT parent_id FROM pabile_product_categories WHERE id = pp.category_id) AS parent_id, (SELECT name FROM pabile_product_categories WHERE id = pp.category_id) AS category_name'))->where("pp.id", $productId)->first();
        $specs = DB::table("pabile_product_specs as pps")->select(DB::raw('pps.*, (SELECT name FROM pabile_spec_keys WHERE id = pps.key) AS keyName'))->where("pps.product_id", $productId)->get();

        if($isDesktop){
            $photos = [];
        }else{
            $photos = DB::table("pabile_product_photos")->where("product_id", $productId)->get();
            foreach($photos as $photo){
                $base64 = base64_encode(Storage::disk('local')->get($photo->thumbnail));
                $photo->thumbnailb64 = $base64;

                $base64_2 = base64_encode(Storage::disk('local')->get($photo->photo));
                $photo->photob64 = $base64_2;
            }
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

    public function getMobilePrefix($mobile){
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

        return $v;
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

            $v = $this->getMobilePrefix($mobile);

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

    public function deliveries(Request $request){
        $data = $request->all();
        $records = DB::table("pabile_orders as po")
        ->select(DB::raw('po.*, pos.name, (SELECT name FROM pabile_riders WHERE id = po.rider_id) AS riderName, (SELECT nickName FROM pabile_riders WHERE id = po.rider_id) AS riderNickName, (SELECT SUM(price) FROM pabile_inventories WHERE order_id = po.id) AS `grandTotal`, (SELECT SUM(price) FROM pabile_fb_orders WHERE order_id = po.id) AS `grandTotalFb`, DATE(date) as dateOnly'))
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

    public function voidOrder(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];

        DB::table('pabile_orders')->where('id', $orderId)->delete();
        DB::table('pabile_fb_orders')->where('order_id', $orderId)->delete();
        DB::table('pabile_inventories')->where("order_id", $orderId)
        ->update([ 
            'price' => null, 
            'order_id' => null
        ]);

        return $this->sendResponse("", 'voidOrder');
    }

    public function submitOrder(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];
        $date = $data["date"];
        $schedule = $data["schedule"];
        $changeFor = $data["changeFor"];
        $notes = $data["notes"];
        $items = $data["items"];
        $bot = (isset($data["bot"])) ? true : false;
        $origin = (isset($data["origin"])) ? $data["origin"] : "pos";

        if(isset($data["realClientId"])){
            $clientId = $data["realClientId"];
        }

        $id = DB::table('pabile_orders')->insertGetId(
            ["client_id" => $clientId, "date" => $date, "schedule" => $schedule, "changeFor" => $changeFor, "notes" => $notes, "created_at" => Carbon::today(), "status_id" => 1, "origin" => $origin]
        );

        

        if(!$bot){
            foreach($items as $item){
                //DB::statement("UPDATE pabile_inventories SET price = " . $item["price"] . ", order_id = " . $id . " WHERE product_id = " . $item["productId"] .  " AND (order_id IS NULL AND inventory_out_id IS NULL) ORDER BY id ASC LIMIT " . $item["qty"]);
                DB::table("pabile_inventories")->whereRaw('product_id = ? AND order_id IS NULL', [$item["productId"]])
                ->orderBy('id', 'asc')
                ->limit($item["qty"])
                ->update([ 
                    'price' => $item["price"], 
                    'order_id' => $id
                ]);
            }
            return $this->sendResponse($id, 'submitOrder');
        }
        else{
            foreach($items as $item){
                DB::table('pabile_fb_orders')->insert(
                    ['order_id' => $id, 'product_id' => $item["productId"], 'qty' => $item["qty"], 'price' => $item["price"]]
                );
            }
            
            return $id;
        }
    }

    public function processOrder(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];
        $isFb = $data["isFb"];
        $items = $data["items"];
        
        if($isFb){
            foreach($items as $item){
                //DB::statement("UPDATE pabile_inventories SET price = " . $item["price"] . ", order_id = " . $id . " WHERE product_id = " . $item["productId"] .  " AND (order_id IS NULL AND inventory_out_id IS NULL) ORDER BY id ASC LIMIT " . $item["qty"]);
                if($item["qty"]){
                    DB::table("pabile_inventories")->whereRaw('product_id = ? AND order_id IS NULL', [$item["id"]])
                    ->orderBy('id', 'asc')
                    ->limit($item["qty"])
                    ->update([ 
                        'price' => $item["price"], 
                        'order_id' => $orderId
                    ]);
                }
            }

            DB::table('pabile_fb_orders')->where("order_id", $orderId)->delete();
        }

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
            DB::table('pabile_orders')->where("id", $delivered["orderId"])
            ->update([ 
                'status_id' => 4
            ]);

            $order = DB::table('pabile_orders')->select("client_id")->where("id", $delivered["orderId"])->first();

            DB::table('pabile_clients')->where("id", $order->client_id)
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

    public function getPurchases(Request $request){
        $data = $request->all();
        $offset = $data["offset"];

        $records = DB::table("pabile_inventories as pi")->select(DB::raw('pi.purchase_id, pi.product_id, (SELECT DATE(created_at) FROM pabile_purchases WHERE id = pi.purchase_id) AS `Date`, (SELECT name FROM pabile_products WHERE id = pi.product_id) AS `Product Name`, pi.cost AS `Cost`, (SELECT COUNT(id) FROM pabile_inventories WHERE purchase_id = pi.purchase_id) AS `Loaded`, COUNT(pi.id) AS `Remaining`'))
        ->whereRaw('pi.order_id IS NULL')
        ->orderBy("pi.purchase_id", "desc")
        ->orderBy("Date", "desc")
        ->groupBy("pi.purchase_id", "pi.product_id", "pi.cost")
        ->limit(20)
        ->offset($offset)
        ->get();

        return $this->sendResponse($records, 'getPurchases');
    }

    public function removePurchases(Request $request){
        $data = $request->all();
        $purchaseId = $data["purchaseId"];

        DB::table('pabile_purchases')->where('id', $purchaseId)->delete();
        DB::table('pabile_inventories')->where('purchase_id', $purchaseId)->delete();
        return $this->sendResponse("", 'removePurchases');
    }

    public function getOrdersInPurchases(Request $request){
        $data = $request->all();
        $purchaseId = $data["purchaseId"];

        $records = DB::table('pabile_inventories as pi')
        ->select(DB::raw('po.*, pi.order_id, COUNT(pi.id) as `count`, DATE(date) as dateOnly, pos.name AS status_name'))
        ->where('pi.purchase_id', $purchaseId)
        ->join('pabile_orders as po', 'po.id', '=', 'pi.order_id')
        ->join('pabile_order_status as pos', 'pos.id', '=', 'po.status_id')
        ->whereNotNull('pi.order_id')
        ->orderBy("po.id", "desc")
        ->groupBy("pi.order_id")
        ->get();

        return $this->sendResponse($records, 'getOrdersInPurchases');
    }

    public function orderInfo(Request $request){
        $data = $request->all();
        $orderId = $data["orderId"];
        $rec = DB::table("pabile_orders as po")
        ->select(DB::raw('po.*, pos.name, (SELECT name FROM pabile_riders WHERE id = po.rider_id) AS riderName, (SELECT nickName FROM pabile_riders WHERE id = po.rider_id) AS riderNickName, (SELECT SUM(price) FROM pabile_inventories WHERE order_id = po.id) AS `grandTotal`, (SELECT SUM(price) FROM pabile_fb_orders WHERE order_id = po.id) AS `grandTotalFb`, DATE(date) as dateOnly'))
        ->where("po.id", $orderId)
        ->join('pabile_order_status as pos', 'pos.id', '=', 'po.status_id')
        ->first();

        if($rec->client_id){
            $client = DB::table("pabile_clients as pc")
            ->join('locations as l', 'l.id_3', '=', 'pc.brgy_id')
            ->select(DB::raw('pc.*, l.name_3, l.varname_3, (SELECT `network` FROM pabile_mobile_prefixes WHERE id = pc.prefix_id) AS mobile_network'))
            ->where("pc.id", $rec->client_id)->first();
            $rec->client = $client;
        }else{
            $rec->client = null;
        }

        return $this->sendResponse($rec, 'orderInfo');
    }

    public function getCategoriesById(Request $request){
        $data = $request->all();
        $catId = $data["catId"];

        $records = DB::table("pabile_product_categories as ppc")->where("parent_id", $catId)
        ->select(DB::raw('ppc.*, (SELECT COUNT(*) FROM pabile_products WHERE ppc.id = category_id) as prodCount'))
        ->get();

        return $this->sendResponse($records, 'getCategoriesById');
    }

    //BOT
    public function botMainProductCategories(Request $request){
        $data = $request->all();

        $records = DB::table("pabile_product_main_categories as ppmc")
        ->select(DB::raw("ppmc.*, (SELECT COUNT(*) FROM pabile_product_categories WHERE parent_id = ppmc.id) AS `catCount`"))
        ->get();

        $elements = [];

        /* foreach($records as $record){
            if($record->catCount){
                $elements[] = array(
                    "title" => $record->name,
                    "subtitle" => $record->catCount . " items"
                );
            }
        } */

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
    }
}
