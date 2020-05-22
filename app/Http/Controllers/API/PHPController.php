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
//
class PHPController extends BaseController
{
    public function nexmoOTP(Request $request){
        $data = $request->all();
        $phoneNumber = $data["phoneNumber"];
        $basic  = new \Nexmo\Client\Credentials\Basic('9662548f', 'fsP0efbavPlPtfY0');
        $client = new \Nexmo\Client($basic);

        $verification = $client->verify()->start([ 
            'number' => '63' . $phoneNumber,
            'brand'  => 'Vonage',
             'code_length'  => '6']);

        return $this->sendResponse(array("verification" => $verification->getRequestId()), 'Depot retrieved successfully.');
    }

    public function nexmoVerifyOTP(Request $request){
        $data = $request->all();
        $requestId = $data["requestId"];
        $code = $data["code"];

        $basic  = new \Nexmo\Client\Credentials\Basic('9662548f', 'fsP0efbavPlPtfY0');
        $client = new \Nexmo\Client($basic);

        $verification = new \Nexmo\Verify\Verification($requestId);
        $result = $client->verify()->check($verification, $code);

        return $this->sendResponse($result->getResponseData(), 'Depot retrieved successfully.');
    }

    public function PHPcategories(Request $request){
        $data = $request->all();

        $records = DB::table("php_categories AS m")->whereNull('parent_id')->get();
        return $this->sendResponse($records, 'PHPcategories retrieved successfully.');
    }

    public function PHPsubcategories(Request $request){
        $data = $request->all();
        $categoryId = $data["categoryId"];
        $records = DB::table("php_categories AS m")->where('parent_id', $categoryId)->get();
        return $this->sendResponse($records, 'PHPsubcategories retrieved successfully.');
    }

    public function PHPProvinceList(Request $request){
        $data = $request->all();;

        $records = DB::table("locations")->distinct()->select("province")->get();

        return $this->sendResponse($records, 'PHPProvinceList retrieved successfully.');
    }

    public function PHPMunicipalList(Request $request){
        $data = $request->all();
        $province = $data["province"];

        $records = DB::table("locations")->select("id_2", "name_2")->where("province", $province)->get();

        return $this->sendResponse($records, 'PHPMunicipalList retrieved successfully.');
    }
}
