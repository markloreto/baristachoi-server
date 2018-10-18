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
use Carbon\Carbon;

class ApiController extends BaseController
{
    public $months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    public function getDepot(){

        $depot = DB::table('depots AS d')->select('d.id', 'd.name', 'd.lat', 'd.lng', 'l.name_1 AS province', 'l.name_2 AS municipal', 'l.name_3 AS brgy')
        ->join('locations AS l', 'd.location_id', '=', 'l.id')
        ->get();

        return $this->sendResponse($depot->toArray(), 'Depot retrieved successfully.');
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
                $records = DB::table($table)->where('depot_id', $depot_id)->skip($skip)->take(10)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->skip($skip)->take(10)->get();
            }else{
                $records = DB::table($table)->skip($skip)->take(10)->get();
            }
        }
        else{
            if(Schema::hasColumn($table, 'depot_id')){
                $records = DB::table($table)->where('depot_id', $depot_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }
            else if(Schema::hasColumn($table, 'staff_id')){
                $records = DB::table($table)->where('staff_id', $staff_id)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }
            else{
                $records = DB::table($table)->whereNotExists(function ($query) use ($table, $staff_id) {
                    $query->select(DB::raw(1))
                          ->from('sync_records')
                          ->whereRaw("sync_records.name = ? AND sync_records.staff_id = ? AND sync_records.data_id = " . $table . ".id", [$table, $staff_id]);
                })->skip($skip)->take(10)->get();
            }
        }
        
        $results = json_decode(json_encode($records), true);
        foreach($results as $result){
            if($staff_id != null)
                \App\SyncRecord::firstOrCreate(['name' => $table, 'staff_id' => $staff_id, 'data_id' => $result["id"]]);
        }

        return $this->sendResponse($records->toArray(), 'records retrieved successfully.');
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

    public function test(Request $request){
        $data = $request->all();
        $year = 2018;
        

        /* $records = DB::table('machines')->select(DB::raw('count(id) as `data`'),DB::raw("CONCAT_WS('-', YEAR(created_at), LPAD(MONTH(created_at), 2, '0')) as monthyear"))
        ->groupby('monthyear')
        ->orderBy("monthyear")
        ->whereYear('created_at', '=', 2018)
        ->get(); */
       
        return $this->sendResponse($monthData, 'depotTotalMachines');
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
