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

        $seq = DB::table('pabile_product_categories')->max('id');

        DB::table('pabile_product_categories')->insert(
            ['name' => $name, 'sequence' => ($seq == null) ? 0 : $seq]
        );

        return $this->sendResponse($data, 'createProductCategory');
    }

    public function getProductCategory(){
        $records = DB::table("pabile_product_categories")->orderBy("sequence", "ASC")->get();
        return $this->sendResponse($records, 'getProductCategory');
    }
}
