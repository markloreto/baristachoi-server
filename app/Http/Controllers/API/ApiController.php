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
use OneSignal;
//
class ApiController extends BaseController
{
    public $months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    public function getDepot(){

        $depot = DB::table('depots AS d')->select('d.id', 'd.name', 'd.lat', 'd.lng', 'l.name_1 AS province', 'l.name_2 AS municipal', 'l.name_3 AS brgy')
        ->join('locations AS l', 'd.location_id', '=', 'l.id')
        ->get();

        return $this->sendResponse($depot->toArray(), 'Depot retrieved successfully.');
    }

    public function getMachineProfile(Request $request){
        $data = $request->all();
        $id = $data["id"];

        $client = array();

        $machine = DB::table("machines")->where('id', $id)->whereNotNull('lat')->first();
        $machinePhoto = DB::table("attachments")->where([["module_id", 5], ["reference_id", $id]])->first();
        if($machine)
            $client = DB::table("clients")->where('id', $machine->client_id)->first();
        return $this->sendResponse(array("machine" => $machine, "client" => $client, "machinePhoto" => $machinePhoto), 'getMachineProfile');
    }

    public function machinesOnMap(Request $request){
        $data = $request->all();

        $machines = DB::table("machines")->select('id', 'lat', 'lng')->whereNotNull('lat')->get();
        return $this->sendResponse($machines->toArray(), 'machinesOnMap');
    }
    
    public function getMachines(Request $request){
        $data = $request->all();
        $records = DB::table("machines")->select('id', 'lat', 'lng')->whereNotNull('lat')->get();
        return $this->sendResponse($records->toArray(), 'Machines');

    }

    public function oneSignal(Request $request){
        $data = $request->all();
        OneSignal::sendNotificationToAll(
            "Some Message", 
            $url = null, 
            $data = null, 
            $buttons = null, 
            $schedule = null
        );
    }

    public function paymentStatus(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $payment_codes = DB::table("payment_codes")->where('staff_id', $staff_id)->orderBy('id', 'desc')->first();
        if($payment_codes){
            $message = ["exp" => $payment_codes->expiration];
        }else{
            $message = ["exp" => null];
        }

        return $this->sendResponse($message, '...');
    }

    public function insertPaymentCode(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];

        $staffQ = DB::table("staffs")->where('id', $staff_id)->first();
        $staff_name =  $staffQ->name;

        $code = $data["code"];
        $mytime = Carbon::now();

        $payment_codes = DB::table("payment_codes")->where('code', $code)->first();
        if($payment_codes){
            if($payment_codes->staff_id){
                $status = ($mytime->diffInMinutes(Carbon::parse($payment_codes->expiration))) ? "Active" : "Inactive";
                $until = ($status == "Active") ? $mytime->diffForHumans(Carbon::parse($payment_codes->expiration), true, false, 5) : "Expired " + $payment_codes->expiration;
                $staffQ = DB::table("staffs")->where('id', $payment_codes->staff_id)->first();
                $staff_name =  $staffQ->name;
                $message = ["status" => $status, "until" => $until, "for" => $staff_name];
            }else{
                $addMinutes = 0;
                $activeCode = DB::table('payment_codes')->whereDate('expiration', '>', $mytime)
                ->where("staff_id", $staff_id)->orderBy('id', 'desc')->first();
                $status = "Reloaded";
                if($activeCode){
                    $addMinutes += $mytime->diffInMinutes($activeCode->expiration);
                    $status = "Renewed";
                }

                $until = Carbon::now()->addDays($payment_codes->days)->addMinutes($addMinutes);

                DB::table('payment_codes')->where('id', $payment_codes->id)->update(['code' => $code, 'staff_id' => $staff_id, "expiration" => $until]);

                $message = ["status" => $status, "until" => Carbon::now()->diffForHumans($until, true, false, 5), "for" => $staff_name, "addMinutes" => $addMinutes];
            }
            
        }
        else{
            $message = ["status" => "Invalid", "until" => "", "for" => ""];
        }

        return $this->sendResponse($message, '...');
    }

    public function pullClientDetails(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];
    }

    public function clientDetails(Request $request){
        $data = $request->all();
        $clientId = $data["clientId"];

        $realId = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $clientId],['table', "clients"]])->first();

        $records = DB::table("clients")->where('id', $realId->converted_id)->first();
        return $this->sendResponse($records, 'Client');

    }

    public function transferMachines(Request $request){
        $data = $request->all();
        $transferFrom = $data["transferFrom"];
        $transferTo = $data["transferTo"];
        $ids = $data["ids"];

        foreach($ids AS $id){
            $res = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $id],['table', "machines"]])->first();
            $converted_id = $res->converted_id;
            DB::table("machine_transfers")->updateOrInsert(['transferFrom' => $transferFrom, 'transferTo' => $transferTo, 'machine_id' => $converted_id], ['transferFrom' => $transferFrom, 'transferTo' => $transferTo, 'machine_id' => $converted_id]);
        }

        return $this->sendResponse($ids, 'transferMachines retrieved successfully.');
    }

    public function checkMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $from = null;
        $fromId = null;
        $transferFromId = null;
        $transferToId = null;

        $transfersCount = DB::table("machine_transfers")->where([['transferTo', $staff_id], ['status', "pending"]])->count();
        if($transfersCount){
            $a = DB::table("machine_transfers")->where([['transferTo', $staff_id],['status', "pending"]])->first();
            $transferToId = $a->transferTo;
            $transferFromId = $a->transferFrom;
            $name = DB::table("staffs")->select("name")->where("id", $transferFromId)->first();
            $from = $name->name;
        }

        return $this->sendResponse(array("from" => $from, "counts" => $transfersCount, "fromId" => $transferFromId), 'checkMachineTransfers retrieved successfully.');
    }

    public function goMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $fromId = $data["fromId"];

        $transfersCount = DB::table("machine_transfers")->where([['transferFrom', $fromId], ['transferTo', $staff_id], ['status', "pending"]])->count();
        if($transfersCount){
            $transfers = DB::table("machine_transfers")->where([['transferFrom', $fromId], ['transferTo', $staff_id],['status', "pending"]])->get();
            foreach($transfers AS $transfer){
                DB::table("machines")->where('id', $transfer->machine_id)
                ->update(['staff_id' => $staff_id]);
            }

            DB::table("machine_transfers")->where([["transferFrom", $fromId], ["transferTo", $staff_id], ["status", "pending"]])
                ->update(['status' => "complete"]);
        }

        return $this->sendResponse($transfersCount, 'checkMachineTransfers retrieved successfully.');
    }

    public function completeMachineTransfers(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];
        $ids = [];
        $to = null;
        
        $q = DB::table("machine_transfers")->where([["transferFrom", $staff_id], ["status", "complete"]]);
        if($q->count()){
            $transfers = $q->get();
            $c = $q->first();
            $to = DB::table("staffs")->select("name")->where("id", $c->transferTo)->first();
            foreach($transfers AS $transfer){
                $res = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $transfer->machine_id],['table', "machines"]])->first();
                array_push($ids, $res->sync_id);
                DB::table('machine_transfers')->where('id', $transfer->id)->delete();
            }
        }

        return $this->sendResponse(array("to" => $to, "transferred" => $ids), 'checkMachineTransfers retrieved successfully.');
    }

    
    public function dealerVersion(){
        $arr = array(); 
        $arr["version"] = 3.9;
        $arr["changelog"] = array(
            array("ADDED", "Checks for number with 09173242410... sorry :(")  
        );
        $json = json_encode($arr, JSON_FORCE_OBJECT); 
        return $this->sendResponse($json, 'dealerVersion retrieved successfully.');
    }

    public function getLogin(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $staff_id = $data["staff_id"];

        $records = \App\Logins::where([["depot_id", $depot_id], ["staff_id", $staff_id]])
        ->whereDate('created_at', Carbon::today())
        ->get();

        return $this->sendResponse($records->toArray(), 'getLogin');
    }

    public function getDealersQuickList(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];

        $depot = DB::table('staffs')->select('id', 'thumbnail', 'name')->where([['depot_id', $depot_id], ['role_id', 3]])->get();
        return $this->sendResponse($depot->toArray(), 'getDealersQuickList');
    }

    public function postProxy(Request $request){
        $data = $request->all();
        $endPoint = $data["endpoint"];
        $body = $data["body"];
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json'
            ]
        ]);

        $response = $client->post($endPoint,
            ['body' => $body]
        );

       /*  echo $response->getBody()->read(4);
        echo $response->getBody()->read(4);
        echo $response->getBody()->read(1024); */

        return $this->sendResponse(json_decode($response->getBody()->read(1024)), 'setLogin');
    }

    public function setLogin(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $staff_id = $data["staff_id"];
        $created_at = $data["created_at"];

        $logins = new \App\Logins;

        $logins->depot_id = $depot_id;
        $logins->staff_id = $staff_id;
        $logins->created_at = $created_at;

        $logins->save();

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'key=AAAAnwpjJ4A:APA91bGhQX_UvzPgzRYhoiowbvBzgSdftHXEB7niQqa0QmY-exWBE61eSNFIZ5SQBQfMqqF21LaGtSBxU7HFtUUX9QYeq1pyXoreHShhmeuAmelFkyFUnYg4JvkLdhvUYrccXKfPDxZF'
            ]
        ]);

        $records = DB::table("staffs")->select("staffs.name", 'depots.name AS depot_name', 'roles.display_name AS role_name')->join('roles', 'staffs.role_id', '=', 'roles.id')->join('depots', 'staffs.depot_id', '=', 'depots.id')->where("staffs.id", $staff_id)->first();

        $response = $client->post('https://fcm.googleapis.com/fcm/send',
        ['body' => json_encode(
            [
                'to' => '/topics/general',
                "collapse_key" => "New Messages",
                "notification" => array(
                    "body" => $records->name . " @ " . Carbon::createFromFormat('Y-m-d H:i:s', $created_at)->toDayDateTimeString(),
                    "title" => "DTR for " . $records->depot_name . " Depot",
                    "icon" => "https://baristachoi-server.firebaseapp.com/assets/images/fcm-icon.png",
                    "click_action" => "https://baristachoi-server.firebaseapp.com/"
                )
            ]
        )]);

        return $this->sendResponse([], 'setLogin');
    }

    public function checkKey(Request $request){
        $data = $request->all();
        $key = $data["key"];
        $id = $data["id"];

        $depot = DB::table('depots')->select('key')->where('id', $id)->first();

        if (Hash::check($key, $depot->key)) {
            return $this->sendResponse(true, 'Data retrieved successfully.');
        }else{
            return $this->sendResponse(false, 'Data retrieved successfully.');
        }
    }

    public function getRoles(){
        $roles = DB::table('roles')->select('id', 'display_name')->get();
        return $this->sendResponse($roles->toArray(), 'Roles retrieved successfully.');
    }

    public function getProductCategories(){
        $product_categories = DB::table('product_categories')->select('id', 'name', 'sequence')->get();
        return $this->sendResponse($product_categories->toArray(), 'product categories retrieved successfully.');
    }

    public function syncPullCount(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];

        if($all){
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->count();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->count();
            }else{
                $records = DB::table($table)->count();
            }
        }
        else{
            if(Schema::hasColumn($table, 'depot_id'))
                $records = DB::table($table)->where('depot_id', $depot_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();

            else if(Schema::hasColumn($table, 'staff_id'))
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();
            else
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                        ->from('sync_records')
                        ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->count();
        }

        return $this->sendResponse($records, 'records retrieved successfully.');
    }

    public function syncPull(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $skip = (int) $data["skip"];
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];

        if($all){
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->skip($skip)->take(3)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(3)->get();
            }else{
                $records = DB::table($table)->skip($skip)->take(3)->get();
            }
        }
        else{
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
            else{
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(3)->get();
            }
        }
        
        $results = json_decode(json_encode($records), true);
        foreach($results as $result){
            if($staff_id != null)
                \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $result["id"]]);
        }

        return $this->sendResponse($records->toArray(), 'records retrieved successfully.');
    }

    public function syncPullDealers(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $all = ($data["all"] == "true") ? true : false;
        $skip = (int) $data["skip"];
        $staff_id = (int) $data["staff_id"];
        $depot_id = (int) $data["depot_id"];
        $realIds = (isset($data["realIds"])) ? $data["realIds"] : array();
        //relational tables
        $relationalTables = $data["relTables"];
        //modules tables
        $moduleTables = $data["modules"];
        $singleData = $data["singleData"];

        if($all){
            if($table == "clients"){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(10)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(10)->get();
            }else{
                $records = DB::table($table)->skip($skip)->take(10)->get();
            }
        }
        else{
            if($table == "clients"){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }

            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }
            else{
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records2')
                          ->whereRaw("sync_records2.name = ? AND sync_records2.staff_id = ? AND sync_records2.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }

            if(count($singleData) == 2){
                if($table == $singleData[0]){
                    $sData = DB::table($table)->where("id", $singleData[1])->get();
                    $records = $sData;
                }
            }
        }

        //$records = $records->toArray();
        $records = json_decode(json_encode($records), true);
        $rels = array();
        $mods = array();
        $newRecord = array();

        foreach($records AS $key => $record){
            if($staff_id != null)
                DB::table("sync_records2")->updateOrInsert(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]], ['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]]);
                //\App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record["id"]]);

            $resIdP = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["id"]],['table', $table]])->first();
            $origId = (int) $resIdP->sync_id;

            if(isset($record["client_id"])){
                if($record["client_id"] != null){
                    $s = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["client_id"]],['table', 'clients']])->first();
                $record["client_id"] = $s->sync_id;
                }
            }

            if(isset($record["machine_id"])){
                if($record["machine_id"] != null){
                    $s = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record["machine_id"]],['table', 'machines']])->first();
                $record["machine_id"] = $s->sync_id;
                }
            }
            
            foreach($relationalTables["tables"] AS $relationalTable){
                $relationalTableName = $relationalTable;
                $relationalCol = $relationalTables["relationalCol"];
                $res = DB::table($relationalTable)->where($relationalCol, $record["id"])->get();
                foreach($res AS $re){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $re->id],['table', $relationalTableName]])->first();
                    $re->id = (int) $resId->sync_id;
                    $re->{$relationalCol} = $origId;
                    $re->table = $relationalTableName;
                    array_push($rels, $re);
                }
            }

            foreach($moduleTables["tables"] AS $moduleTable){
                $moduleTableName = $moduleTable;
                $moduleId = $moduleTables["module_id"];
                $res = DB::table($moduleTable)->where([["module_id", $moduleId], ["reference_id", $record["id"]]])->get();
                foreach($res AS $re){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $re->id],['table', $moduleTableName]])->first();
                    $re->id = (int) $resId->sync_id;
                    $re->reference_id = $origId;
                    $re->table = $moduleTableName;
                    array_push($mods, $re);
                }
            }

            
            $record["id"] = $origId;
            $record["table"] = $table;

            foreach($realIds AS $realId){
                if($record[$realId["col"]] != null){
                    $resId = DB::table("converted_synchs2")->select('sync_id')->where([['converted_id', $record[$realId["col"]]],['table', $realId["table"]]])->first();
                    $record[$realId["col"]] = $resId->sync_id;
                }
            }
            array_push($newRecord, $record);
        }

        $results = array("records" => $newRecord, "modules" => $mods, "relations" => $rels);
    

        /* foreach($records as $record){
            if($staff_id != null)
                \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $record->id]);

            $resId = DB::table("converted_synchs")->select('sync_id')->where([['converted_id', $record->id],['table', $table]])->first();
            $record->id = (int) $resId->sync_id;

            if($table == "machines"){
                if($record->client_id != null){
                    $resId = DB::table("converted_synchs")->select('sync_id')->where([['converted_id', $record->client_id],['table', 'clients']])->first();
                    $record->client_id = $resId->sync_id;
                }
                
            }
        } */

        return $this->sendResponse($results, 'records retrieved successfully.');
    }

    public function syncPushDealers(Request $request){
        $data = $request->all();
        $records = $data["records"];
        $table = $data["table"];
        $recordIds = array();
        foreach($records AS $record){
            //get long ID
            $syncId = $record["id"];
            //staff ID
            $staff_id = $record["staff_id"];
            //relational tables
            $relationalTables = $record["tables"];
            //modules tables
            $moduleTables = $record["modules"];
            //check for new or update only
            $mode = (intval($record["sync"]) == 0) ? "new" : "update";
            //remove uneccessary properties
            unset($record["id"]);
            unset($record["sync"]);
            unset($record["tables"]);
            unset($record["modules"]);
            //Retrieve Converted IDs if any
            if(isset($record["client_id"])){
                if($record["client_id"] != null){
                    $s = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $record["client_id"]],['table', 'clients']])->first();
                $record["client_id"] = $s->converted_id;
                }
            }

            if(isset($record["machine_id"])){
                if($record["machine_id"] != null){
                    $s = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $record["machine_id"]],['table', 'machines']])->first();
                $record["machine_id"] = $s->converted_id;
                }
            }

            $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $syncId],['table', $table]])->count();

            if($alreadyConverted){
                $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $syncId],['table', $table]])->first();
                $id = $convertedID->converted_id;

                DB::table($table)->where('id', $id)
                ->update($record);

                DB::table("sync_records2")->where([
                    ["name", $table],
                    ["data_id", $id],
                    ["staff_id", "!=", $staff_id]
                ])->delete();

                array_push($recordIds, array("table" => $table, "id" => $syncId));
            }else{
                $id = DB::table($table)->insertGetId($record);
                DB::table('converted_synchs2')->insert(
                    ['table' => $table, 'sync_id' => $syncId, 'converted_id' => $id]
                );
                array_push($recordIds, array("table" => $table, "id" => $syncId));

                //\App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
                DB::table("sync_records2")->updateOrInsert(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id], ['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
            }
            
            foreach($relationalTables AS $relationalTable){
                $relationalTableName = $relationalTable["table"];
                $relationalCol = $relationalTable["relationalCol"];

                DB::table($relationalTableName)->where($relationalTable["relationalCol"], $id)->delete();
                
                foreach($relationalTable["data"] AS $relationalData){
                    $relationalSyncId = $relationalData["id"];
                    $relationalData[$relationalCol] = $id;

                    unset($relationalData["id"]);
                    unset($relationalData["sync"]);

                    /* $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $relationalSyncId],['table', $relationalTableName]])->count();

                    if($alreadyConverted){
                        $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $relationalSyncId],['table', $relationalTableName]])->first();
                        $conId = $convertedID->converted_id;
        
                        DB::table($relationalTableName)->where('id', $conId)
                        ->update($relationalData);
        
                        DB::table("sync_records2")->where([
                            ["name", $relationalTableName],
                            ["data_id", $conId],
                            ["staff_id", "!=", $staff_id]
                        ])->delete();

                        array_push($recordIds, array("table" => $relationalTableName, "id" => $relationalSyncId));
                    }else{ */
                        $relId = DB::table($relationalTableName)->insertGetId($relationalData);
                        DB::table('converted_synchs2')->insert(
                            ['table' => $relationalTableName, 'sync_id' => $relationalSyncId, 'converted_id' => $relId]
                        );
                        array_push($recordIds, array("table" => $relationalTableName, "id" => $relationalSyncId));
                    /* } */

                    
                }
            }

            foreach($moduleTables AS $moduleTable){
                $moduleTableName = $moduleTable["table"];
                $moduleId = $moduleTable["module_id"];

                DB::table($moduleTableName)->where([["reference_id", $id], ["module_id", $moduleId]])->delete();

                foreach($moduleTable["data"] AS $moduleData){
                    $moduleSyncId = $moduleData["id"];
                    $moduleData["reference_id"] = $id;

                    unset($moduleData["id"]);
                    unset($moduleData["sync"]);

                    /* $alreadyConverted = DB::table("converted_synchs2")->where([['sync_id', $moduleSyncId],['table', $moduleTableName]])->count();

                    if($alreadyConverted){
                        $convertedID = DB::table("converted_synchs2")->select('converted_id')->where([['sync_id', $moduleSyncId],['table', $moduleTableName]])->first();
                        $conId = $convertedID->converted_id;
        
                        DB::table($moduleTableName)->where('id', $conId)
                        ->update($moduleData);
        
                        DB::table("sync_records2")->where([
                            ["name", $moduleTableName],
                            ["data_id", $conId],
                            ["staff_id", "!=", $staff_id]
                        ])->delete();
                        array_push($recordIds, array("table" => $moduleTableName, "id" => $moduleSyncId));
                    }else{ */
                        $relId = DB::table($moduleTableName)->insertGetId($moduleData);
                        DB::table('converted_synchs2')->insert(
                            ['table' => $moduleTableName, 'sync_id' => $moduleSyncId, 'converted_id' => $relId]
                        );
                        array_push($recordIds, array("table" => $moduleTableName, "id" => $moduleSyncId));
                    /* } */
                }
            }

        }

        return $this->sendResponse($recordIds, 'records retrieved successfully.');
    }

    public function syncPush(Request $request){
        $data = $request->all();
        $table = $data["table"];
        $record = $data["record"];
        $staff_id = (int) $data["staff_id"];
        
        foreach($record AS $key => $value){
            if($key == "created_date"){
                if (strpos($value, 'Z') !== false) {
                    $record['created_date'] = substr($value, 0, -4); //
                }
            }
            if($key == "dealer_id"){
                $fd = DB::table("converted_synchs")->where([['sync_id', $record['dealer_id']],['table', 'staffs']])->count();
                if($fd){
                    $fd2 = DB::table("converted_synchs")->select('converted_id', 'id')->where([['sync_id', $record['dealer_id']],['table', 'staffs']])->first();
                    $record['dealer_id'] = (int) $fd2->converted_id;
                }
            }

            if($key == "payee"){
                $fd = DB::table("converted_synchs")->where([['sync_id', $record['payee']],['table', 'staffs']])->count();
                if($fd){
                    $fd2 = DB::table("converted_synchs")->select('converted_id', 'id')->where([['sync_id', $record['payee']],['table', 'staffs']])->first();
                    $record['payee'] = (int) $fd2->converted_id;
                }
            }
        }

        $syncId = $record["id"];

        $s1 = DB::table($table)->where('id', $syncId)->count();
        $s2 = DB::table("converted_synchs")->where([['sync_id', $syncId],['table', $table]])->count();

        if($s1){ // Original Data OR Converted Data
            //updates
            $id = (int) $record["id"];
            unset($record["id"]);
            unset($record["sync"]);

            DB::table($table)->where('id', $id)
                ->update($record);

            DB::table("sync_records")->where([
                ["name", $table],
                ["data_id", $id],
                ["staff_id", "!=", $staff_id]
            ])->delete();
        }else if($s2){ //Error
            //updates
            $r = DB::table("converted_synchs")->select('converted_id', 'id')->where('sync_id', $syncId)->first();
            $id = (int) $r->converted_id;
            unset($record["id"]);
            unset($record["sync"]);

            DB::table($table)->where('id', $id)
                ->update($record);

            DB::table("sync_records")->where([
                ["name", $table],
                ["data_id", $id],
                ["staff_id", "!=", $staff_id]
            ])->delete();
        }else{
            unset($record["id"]);
            unset($record["sync"]);
            $id = DB::table($table)->insertGetId($record);
            DB::table('converted_synchs')->insert(
                ['table' => $table, 'sync_id' => $syncId, 'converted_id' => $id]
            );

            \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $id]);
        }
        return $this->sendResponse($id, 'records retrieved successfully.');
    }

    public function syncDelete(Request $request){
        $data = $request->all();
        $records = $data["records"];
        $staff_id = (int) $data["staff_id"];
        
        foreach($records AS $key => $value){
            DB::table($value["table_name"])->where('id', $value["reference_id"])->delete();
            \App\SyncDeleteRecord::firstOrCreate(['name' => $value["table_name"], 'staff_id' => $staff_id, 'data_id' => $value["reference_id"]]);
        }

        $r = DB::table("sync_delete_records")->select("name", "data_id")->whereExists(function ($query) use ($staff_id) {
            $query->select(DB::raw(1))
                  ->from('sync_records')
                  ->whereRaw("sync_records.name = sync_delete_records.name AND sync_records.staff_id != sync_delete_records.staff_id AND sync_delete_records.data_id = sync_records.data_id");
        })->get();



        return $this->sendResponse($r->toArray(), 'records retrieved successfully.');
    }

    //Server Firebase
    public function accessByEmail(Request $request){
        $data = $request->all();
        $email = $data["email"];

        $records = DB::table("staffs")->select("staffs.*", 'depots.name AS depot_name', 'roles.display_name AS role_name')->join('roles', 'staffs.role_id', '=', 'roles.id')->join('depots', 'staffs.depot_id', '=', 'depots.id')->where("staffs.email", $email)->first();

        return $this->sendResponse($records, 'Depot retrieved successfully.');
    }

    public function updateFCMToken(Request $request){
        $data = $request->all();
        $token = $data["token"];
        $id = $data["id"];

        $records = DB::table('staffs')
        ->where('id', $id)
        ->update(['fcm_token' => $token]);

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'key=AAAAnwpjJ4A:APA91bGhQX_UvzPgzRYhoiowbvBzgSdftHXEB7niQqa0QmY-exWBE61eSNFIZ5SQBQfMqqF21LaGtSBxU7HFtUUX9QYeq1pyXoreHShhmeuAmelFkyFUnYg4JvkLdhvUYrccXKfPDxZF'
            ]
        ]);

        $response = $client->post('https://iid.googleapis.com/iid/v1/'.$token.'/rel/topics/general');

        return $this->sendResponse($records, 'Token Updated');
    }

    /* Stat */

    //Top 10 dealers
    public function topTenDealers(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];

        $records = DB::table('staffs AS s')->select("s.name", "depots.name AS depot_name", "s.thumbnail",
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS mySold"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0) AS last_sold"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots', 's.depot_id', '=', 'depots.id')->whereRaw('s.role_id = 3')->having('mySold', '!=', 0)->orderBy('mySold', 'desc')->limit(10)->get();
        return $this->sendResponse($records, 'Top 10 Dealers retrieved successfully.');
    }

    //Top 10 Depot

    public function topTenDepot(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];
        $records = DB::table('staffs AS s')->select("de.name AS depot_name", 
        DB::raw("SUM(IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0)) AS mySold"),
        DB::raw("SUM(IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0)) AS last_sold"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.depot_id = de.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots AS de', 's.depot_id', '=', 'de.id')->whereRaw('s.role_id = 3')->groupBy('de.id')->having('mySold', '!=', 0)->orderBy('mySold', 'desc')->limit(10)->get();
        return $this->sendResponse($records, 'Top 10 Depot retrieved successfully.');
    }

    public function depotDashboardDealers(Request $request){
        $data = $request->all();
        $thisMonth = $data["thisMonth"];
        $lastMonth = $data["lastMonth"];
        $depot_id = $data["depot_id"];
        $records = DB::table('staffs AS s')->select("s.name", "depots.name AS depot_name", "s.thumbnail", "s.id",
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS powder_thisMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $lastMonth[1] AND YEAR(d.created_date) = $lastMonth[0]), 0) AS powder_lastMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.id = 3 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS cup_thisMonth"),
        DB::raw("IFNULL((SELECT SUM(ifnull(i.sold, 0)) FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.id = 7 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0]), 0) AS machine_thisMonth"),
        DB::raw("(SELECT d.created_date FROM inventories i INNER JOIN products p ON i.product_id = p.id INNER JOIN disrs d ON i.reference_id = d.id WHERE d.dealer_id = s.id AND p.category = 1 AND i.module_id = 2 AND i.type = 2 AND MONTH(d.created_date) = $thisMonth[1] AND YEAR(d.created_date) = $thisMonth[0] ORDER BY i.id DESC LIMIT 1) AS last_updated")
        )->join('depots', 's.depot_id', '=', 'depots.id')->whereRaw('s.role_id = 3 AND depots.id = ' . $depot_id)->orderBy('powder_thisMonth', 'desc')->get();
        return $this->sendResponse($records, 'depotDashboardDealers');
    }
    
    //Total Clients
    public function depotTotalClients(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $records = DB::table('clients AS c')
        ->join('staffs AS s', 's.id', '=', 'c.staff_id')->whereRaw('s.depot_id = ' . $depot_id)->count();
        return $this->sendResponse($records, 'depotTotalClients');
    }

    //Total Machines
    public function depotTotalMachines(Request $request){
        $data = $request->all();
        $depot_id = $data["depot_id"];
        $records = DB::table('machines AS m')
        ->join('staffs AS s', 's.id', '=', 'm.staff_id')->whereRaw('s.depot_id = ' . $depot_id)->count();
        return $this->sendResponse($records, 'depotTotalMachines');
    }

    public function dealerFullInfo(Request $request){
        $data = $request->all();
        $staff_id = $data["staff_id"];

        $record = DB::table("staffs AS s")
        ->select("s.*",
        DB::raw("IFNULL((SELECT COUNT(*) FROM machines WHERE staff_id = $staff_id), 0) AS total_machines"),
        DB::raw("IFNULL((SELECT COUNT(*) FROM clients WHERE staff_id = $staff_id), 0) AS total_clients")
        )
        ->where("s.id", $staff_id)->first();
        return $this->sendResponse($record, 'dealerFullInfo');
    }

    public function depotClientsMonthInc(Request $request){
        $data = $request->all();
        $year = $data["year"];
        $depot_id = $data["depot_id"];
        $monthData = [];
        $until = Carbon::parse("next month")->format("F Y");

        foreach($this->months AS $month){
            if("$month $year" != $until){
                $m = Carbon::parse("$month $year")->subMonth()->endOfMonth()->toDateTimeString();
                $r = DB::table('machines AS m')->whereDate("m.created_at", "<=", $m)
                ->join('staffs AS s', 's.id', '=', 'm.staff_id')->whereRaw('s.depot_id = ' . $depot_id)
                ->count();
                array_push($monthData, ["month" => Carbon::parse("$month")->format("M"), "count" => $r, "test" => $until]);
            }else{
                break;
            }
            
        }

        return $this->sendResponse($monthData, 'depotClientsMonthInc');
    }

    public function machinesNearby(Request $request){
        $data = $request->all();
        $lat = $data["lat"];
        $lng = $data["lng"];
        $distance = isset($data["distance"]) ? $data["distance"] : 1;

        $records = DB::table("machines AS m")->select("m.lat", "m.lng", "m.id", 
        DB::raw("((ACOS(SIN($lat * PI() / 180) * SIN(m.lat * PI() / 180) + COS($lat * PI() / 180) * COS(m.lat * PI() / 180) * COS(($lng - m.lng) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) as distance"))
        ->having('distance', '<=', $distance)
        ->orderBy('distance', 'asc')->get();

        return $this->sendResponse($records, 'machinesNearby');
    }

    public function paypalPay2(Request $request){
        $data = $request->all();

        DB::table('log_tests')->insert(
            ['message' => json_encode($data)]
        );

        if($data["payment_status"] === "Completed"){
            $mytime = Carbon::now();
            

            $qty = (int) $data["quantity"];

            $codes = "";
            $days = 0;

            $isTrue = false;
            $string = "";

            for ($x = 0; $x < $qty; $x++) {
                do {
                    $string = str_random(6);
                    $isExist = DB::table("payment_codes")->where('code', $string)->count();

                    if($data["option_selection1"] == "1 Month")
                        $days = 30;
                    if($data["option_selection1"] == "3 Months")
                        $days = 90;
                    if($data["option_selection1"] == "6 Months")
                        $days = 180;
                    if($data["option_selection1"] == "1 Year")
                        $days = 360;
                    

                    if($isExist){
                        $isTrue = true;
                    }
                    else{
                        $isTrue = false;
                        DB::table('payment_codes')->insert(
                            ['code' => $string, "days" => $days]
                        );
                    }
                } while ($isTrue);

                $codes .= "<div><strong>" . $string . "</strong></div>";
            }

            $to_name = $data["first_name"] . " " . $data["last_name"];
            $to_email = $data["payer_email"];

            $rec = array(
                "name" => $data["first_name"], 
                "body" => "<div>Here's your <strong>Payment Code</strong> and will expire <strong>" . $data["option_selection1"] . "</strong> after use:</div>" . $codes . "<br/>",
                "expiration" => $data["option_selection1"]
            );

            Mail::send('emails.mail', $rec, function($message) use ($to_name, $to_email) {
                $message->to($to_email, $to_name)->subject('New Payment Codes');
                $message->from("techsupport@sugbu.me", "Dealer-app Payment Codes");

            });
        }
   
        return $this->sendResponse($data, 'paypalPay2');
    }

    public function serverLogin(Request $request){
        $data = $request->all();
        $username = $data["username"];
        $password = $data["password"];
        $apiAccess = "";
        $userData = "";

        $record = DB::table("staffs")->where([['username', $username], ['passcode', $password]])->count();

        if($record){
            $apiAccess = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6IjJmZjQxODQzYjQ3ZTI0MWNmMzQ2Y2NkYmZlMzU2MzkwMGJkODBhMjgzYzg5OWM0NDA3Y2U2ZjcwOGM2N2Y0YzIwOTQ5NzE3MmI0Y2EyMzc3In0.eyJhdWQiOiI0IiwianRpIjoiMmZmNDE4NDNiNDdlMjQxY2YzNDZjY2RiZmUzNTYzOTAwYmQ4MGEyODNjODk5YzQ0MDdjZTZmNzA4YzY3ZjRjMjA5NDk3MTcyYjRjYTIzNzciLCJpYXQiOjE1NzA4NDkwOTYsIm5iZiI6MTU3MDg0OTA5NiwiZXhwIjoxNjAyNDcxNDk2LCJzdWIiOiIxIiwic2NvcGVzIjpbIioiXX0.QB1aSw3vZKFnwFELrLhnLoTO4tS-2SnS-z8m0TTjkVRFrwGUglPZtvFm9uZP0fbexxaDavcKlNUdNsVpoD0Nv7cI-sJh-eiiPqxC0ewQEoH2XO6wJ3Z7Jz1Lwd3LSgQVm6CSpouKfM0G0jR_4mcpdbOS4yA1_0-VsAVdThbniNAQlAB9rXSJaEVX_YdqnL0aGTQqgGNYFGd153HNU-yrO2JpcwglAwXi3ELdGqDeDhw_WtQjNqzGzhXR5Ie5Qeh3V4nC_KJmb4F1ZQapPywOAVgZwtN4aLQQvO50nZWU_p5354br7pn3KJvUYStnmev3oO0o6awhoEssJe2n0N6o6qCw5K6LwHooT9rYLbdZFR3GIyM4WgpwF-dng3SzXxGvMdDciQ3xe4ynsTQ5oSFqDAXY-upgbCdvHQWO_ulVNGXK14VOfiiJM0hDNn3HIqwS6WK5wkMbL9yyOXlMOW3kaVSrGsiPTN9gOthCMrdLqdd6LLP92KDMGl_sC54iKIsBeCa5KNAyFQKd6w-RGo1FGb68JHwvvL8ToI4go4t4-4guEg7Nk8OVXmYrqU1mUd-vsxcPtU-_pX86pjLYgMgcJ8Lsj3D-D_U-YK53AJriz0KVvijURfjeGZLHVZ7rL1IpgPJJ3bvWv4FlDSaq9Oo8Cm4W9kIvH6hCoe_G3UKH_dY";
            
            $userData = DB::table('staffs AS s')->select('s.*', 'r.display_name AS role_name')->where([['username', $username], ['passcode', $password]])
            ->join('roles AS r', 's.role_id', '=', 'r.id')
            ->first();

            $resizedThumbnail = Image::make($userData->thumbnail);
            $resizedThumbnail->resize(100, 100);

            $userData->thumbnail = (string) $resizedThumbnail->encode('data-url');;

            if(!$data["inDevelopment"]){
                OneSignal::sendNotificationUsingTags(
                    $userData->name . " logged in to the server",
                    array(
                        ["field" => "tag", "key" => "userId", "relation" => "=", "value" => 55]
                    ),
                    $url = null,
                    $data = null,
                    $buttons = null,
                    $schedule = null
                );
            }
            


            //$userData = DB::table("staffs")->where([['username', $username], ['passcode', $password]])->first();
            
        }
            
       
        return $this->sendResponse(array("withAccess" => $record, "apiAccess" => $apiAccess, "userData" => $userData), 'serverLogin22');
    }

    public function resetPassword(Request $request)
    {
        $data = $request->all();
        $username = $data["username"];
        $password = $data["password"];
        $key = $data["key"];
        $keyCorrect = false;

        $isExist = DB::table("staffs")->where('username', $username)->count();
        if($isExist){
            $depotId = DB::table("staffs")->select("depot_id", "id")->where('username', $username)->first();
            $hashed = DB::table("depots")->select("key")->where('id', $depotId->depot_id)->first();
            if (Hash::check($key, $hashed->key)) {
                $keyCorrect = true;
                DB::table("staffs")->where('id', $depotId->id)->update(['passcode' => $password]);
            }
        }

        return $this->sendResponse(array("userExist" => $isExist, "keyCorrect" => $keyCorrect), 'resetPassword2');
    }


    //Temp
    public function importOldServer(){
        
        DB::table("machines_copy")->whereNotNull('owner_id')->orderBy('id')->chunk(100, function ($machines) {
            foreach($machines AS $key => $value){
                $client = DB::table("clients_copy")->where("id", $value->owner_id)->first();
                $staff = DB::table("staffs")->where("mapper_id", $value->creator_id)->first();
                
                //client thumbnail
                
                if(is_object($client)){
                    if($client->photo !== null || $client->photo !== ""){
                        try {
                            $ct = Image::make($value->photo);
                            $ct->resize(150, null, function ($constraint) {
                                $constraint->aspectRatio();
                            });
    
                            $client_thumbnail = (string) $ct->encode('data-url');
                        }
                        catch (\Exception $e) {
                            $client_thumbnail = null;
                        }
                    }else
                        $client_thumbnail = null;
                    
    
                    $client_id = DB::table('clients')->insertGetId(
                        [
                            'name' => $client->name, 
                            'alias' => ($client->alias) ? $client->alias : null,
                            'company' => ($client->company) ? $client->company : null,
                            'email' => ($client->email) ? $client->email : null,
                            'photo' => ($client->photo) ? $client->photo : null,
                            'thumbnail' => $client_thumbnail,
                            'lat' => null,
                            'lng' => null,
                            'created_at' => date("Y-m-d H:i:s", $client->created_date / 1000),
                            'updated_at' => date("Y-m-d H:i:s", $client->updated_date / 1000),
                            'deleted_at' => null,
                            'staff_id' => $staff->id,
                            'location_id' => null,
                        ]
                    );
    
                    if($client->contact){
                        DB::table('contacts')->insert(
                            [
                                'reference_id' => $client_id,
                                'contact' => $client->contact,
                                'module_id' => 3
                            ]
                        );
                    }
    
                    if($client->contact2){
                        DB::table('contacts')->insert(
                            [
                                'reference_id' => $client_id,
                                'contact' => $client->contact2,
                                'module_id' => 3
                            ]
                        );
                    }
                }else{
                    $client_id = null;
                }

                $lat = $value->lat;
                $lng = $value->lng;
                $location = DB::table("locations")->select("id", "name_1", "name_2", "name_3")->whereRaw("st_contains(SHAPE, ST_GeomFromText('POINT($lng $lat)', 1))", [])->first();
                
                if(!is_object($location)){
                    $loc = null;
                }else{
                    $loc = $location->id;
                    // echo "$lat $lng";// , $location->name_1 + " " + $location->name_2 + " " + $location->name_3 + "<br/>";
                    // echo " " . $location->name_1 . " " . $location->name_2 . " " . $location->name_3 . " $staff->id $created_at <br/>";
                }

                if(!is_object($value)){
                    $thumbnail = null;
                }else{
                    if($value->photo !== null || $value->photo !== ""){
                        try {
                            $img = Image::make($value->photo);
                            $img->resize(150, null, function ($constraint) {
                                $constraint->aspectRatio();
                            });
    
                            $thumbnail = (string) $img->encode('data-url');
                        }
                        catch (\Exception $e) {
                            $thumbnail = null;
                        }
                        
                    }else
                        $thumbnail = null;
                }

                $machineId = DB::table('machines')->insertGetId(
                    [
                        'staff_id' => $staff->id,
                        'client_id' => $client_id,
                        'created_at' => date("Y-m-d H:i:s", $value->created_date / 1000),
                        'updated_at' => date("Y-m-d H:i:s", $value->updated_date / 1000),
                        'location_id' => $loc,
                        'accuracy' => $value->accuracy,
                        'delivery' => ($value->delivery === "Unspecified") ? "Sat" : $value->delivery,
                        'lat' => $lat,
                        'lng' => $lng,
                        'machine_type' => ($value->machine_type == "Unspecified") ? "Other" : $value->machine_type,
                        'sequence' => $value->sequence,
                        'photo' => ($value->photo) ? $value->photo : null,
                        'thumbnail' => $thumbnail,
                        'establishment_type' => ($value->establishment_type) ? $value->establishment_type : null,
                        'deleted_at' => null

                    ]
                );
                echo $machineId . "<br/>";
            }
        });
        
    }
}
