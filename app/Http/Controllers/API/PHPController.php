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

        $records = DB::table("locations")->distinct()->select("province")->orderBy("province", "ASC")->get();

        return $this->sendResponse($records, 'PHPProvinceList retrieved successfully.');
    }

    public function PHPMunicipalList(Request $request){
        $data = $request->all();
        $province = $data["province"];

        $records = DB::table("locations")->distinct()->select("id_2", "name_2")->where("province", $province)->orderBy("name_2", "ASC")->get();

        return $this->sendResponse($records, 'PHPMunicipalList retrieved successfully.');
    }

    public function PHPBrgyList(Request $request){
        $data = $request->all();
        $municipalId = $data["municipalId"];

        $records = DB::table("locations")->select("id_3", "name_3", "varname_3")->where("id_2", $municipalId)->orderBy("name_3", "ASC")->get();

        return $this->sendResponse($records, 'PHPBrgyList retrieved successfully.');
    }

    public function getLoc(Request $request){
        
        $data = $request->all();
        $lat = $data["lat"];
        $lng = $data["lng"];

        $loc = DB::table("locations")->select(DB::raw("region, province, name_2 AS municipal, name_3 AS brgy"))->whereRaw("MbrWithin(GeomFromText(?), shape)", ['POINT('.$lng.' '.$lat.')'])->first();

        if($loc){
            $data = $loc->province . ", " . $loc->municipal . ", " . $loc->brgy; 
        }else{
            $data = "";
        }

        return $this->sendResponse($data, 'getLoc retrieved successfully.');
    }

    public function phpContributionSubmit(Request $request){
        $data = $request->all();
        $lat = $data["lat"];
        $lng = $data["lng"];
        $locations = $data["locations"];
        $categories = $data["categories"];
        $message = ($data["message"] == "") ? null : $data["message"];
        $photo = $data["photo"];

        if($photo != null){
            $milliseconds = round(microtime(true) * 1000);
            Storage::disk('local')->put("peoplehelppeople/" . $milliseconds . ".jpg", /* "data:image/*;base64," . */ base64_decode($photo));
        }else{
            $milliseconds = null;
        }
        

        $id = DB::table('php_contributions')->insertGetId(
            ['lat' => $lat, 'lng' => $lng, 'photo' => $milliseconds, 'message' => $message]
        );

        foreach($categories as $category){
            DB::table('php_contribution_categories')->insert(
                ['contribution_id' => $id, 'category_id' => $category["id"]]
            );
        }

        foreach($locations as $location){
            DB::table('php_contribution_locations')->insert(
                ['contribution_id' => $id, 'brgy_id' => $location["id"]]
            );
        }

        return $this->sendResponse($data, 'phpContributionSubmit retrieved successfully.');;
    }

    public function ETinda(Request $request){
        $data = $request->all();
        $challenge = $data["hub_challenge"];
        return response($challenge, 200);
    }
}
