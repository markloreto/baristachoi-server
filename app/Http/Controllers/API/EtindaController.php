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
            $category = DB::table("pabile_product_categories")->where('parent_id', $mainCategory->id)->get();
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

        DB::table('pabile_spec_keys')->insert(
            ['name' => $name]
        );

        return $this->sendResponse($data, 'createSpecKeys');
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

        $seq = DB::table('pabile_products')->max('id');

        $id = DB::table("pabile_products")->insertGetId(
            ["name" => $name, "category_id" => $category, "description" => $description, "sequence" => $seq, "enabled" => $enabled]
        );

        $milliseconds = round(microtime(true) * 1000);
        foreach($photos as $photo){
            $photoLink = $milliseconds + $photo->index;
            Storage::disk('local')->put("pabile/photo" . $photoLink . ".jpg", /* "data:image/*;base64," . */ base64_decode($photo->photo));
            Storage::disk('local')->put("pabile/thumbnail" . $photoLink . ".jpg", /* "data:image/*;base64," . */ base64_decode($photo->thumbnail));
            
            DB::table('pabile_product_photos')->insert(
                ["photo " => "pabile/photo" . $photoLink . ".jpg", "thumbnail" => "pabile/thumbnail" . $photoLink . ".jpg", "primary" => ($primaryPhoto === $photo->index) ? 1 : 0, "product_id" => $id, "index" => $photo->index]
            );
        }

        foreach($specs as $spec){
            DB::table('pabile_product_specs')->insert(
                ["product_id" => $id, "key" => $spec->key->key, "value" => $spec->value]
            );
        }

        foreach($tags as $tag){
            DB::table('pabile_product_tags')->insert(
                ["product_id" => $id, "name" => $tag->value]
            );
        }

        return $this->sendResponse($id, 'createNewProduct');
    }
}
