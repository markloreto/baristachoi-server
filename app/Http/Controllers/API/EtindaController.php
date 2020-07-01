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

        $seq = DB::table('pabile_products')->max('id');

        $id = DB::table("pabile_products")->insertGetId(
            ["name" => $name, "category_id" => $category, "description" => $description, "sequence" => $seq, "enabled" => $enabled, "barcode" => $barcode, "price" => $price]
        );;

        $milliseconds = round(microtime(true) * 1000);
        foreach($photos as $photo){
            $photoLink = $milliseconds + $photo["index"];

            $image = preg_replace('/^data:image\/\w+;base64,/', '', $photo["photo"]);
            /* $image = str_replace('data:image/*;base64,', '', $photo["photo"]);
            $image = str_replace('data:image/png;base64,', '', $image);
            $image = str_replace('data:image/jpeg;base64,', '', $image); */
            $image = str_replace(' ', '+', $image);

            $imageThumb = preg_replace('/^data:image\/\w+;base64,/', '', $photo["thumbnail"]);
            /* $imageThumb = str_replace('data:image/*;base64,', '', $photo["thumbnail"]);
            $imageThumb = str_replace('data:image/png;base64,', '', $imageThumb);
            $imageThumb = str_replace('data:image/jpeg;base64,', '', $imageThumb); */
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

        return $this->sendResponse($data, 'createNewProduct');
    }

    public function getProducts(Request $request){
        $data = $request->all();
        $categoryId = $data["categoryId"];

        $records = DB::table("pabile_products as pp")->select(DB::raw('pp.*, (SELECT COUNT(id) FROM pabile_inventories pi WHERE pi.product_id = pp.id AND pi.order_id IS NULL AND pi.inventory_out_id IS NULL) AS inventory'))->where("pp.category_id", $categoryId)->get();
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
        ->select(DB::raw('pp.*, ppc.name AS category_name'))
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
                    ["product_id" => $model["id"], "cost" => $model["cost"], "price" => $model["price"], "purchase_id" => $id]
                );
            }
        }

        return $this->sendResponse("Success", 'searchProducts');
    }
}
