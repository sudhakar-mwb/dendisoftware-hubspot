<?php

namespace Wave\Http\Controllers\HubSpot;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DB;
use Carbon\Carbon;
use \HubSpot\Factory;
// use \HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput;

// use HubSpot\Factory;
// use HubSpot\Client\Crm\Objects\Notes\ApiException;
use HubSpot\Client\Crm\Objects\Notes\Model\AssociationSpec;
use HubSpot\Client\Crm\Objects\Notes\Model\PublicAssociationsForObject;
use HubSpot\Client\Crm\Objects\Notes\Model\PublicObjectId;
use HubSpot\Client\Crm\Objects\Notes\Model\SimplePublicObjectInputForCreate;
use App\Traits\DendiApis;
use App\Jobs\CampaignDataSync;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput;


class HubspotController extends Controller
{
  use DendiApis;
  /**
   * Create a new controller instance.
   *
   * @return void
   */
  public function __construct()
  {
  }

  public function contactActivities(Request $request)
{
    $date   = Carbon::now();
    $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'hubspot_contactIds')->first();
    if (empty($dbData)) {
      $contactIds = array();
    } else {
      $contactIds = json_decode($dbData->option_value);
    }
    $data = $request->getContent();
    $data = json_decode($data);

    if (!empty($data)) {
      foreach ($data as $key => $value) {
        if (!empty($value->propertyName)) {
          $changeSource  = $value->changeSource;
          $propertyName  = $value->propertyName;
          $propertyValue = $value->propertyValue;
          if ('FORM' == $changeSource && 'create_contact_on_dendi' == $propertyName && 'Yes' == $propertyValue) {
            $contactIds[]  = (int)$value->objectId;
          } 
        }
      }
    }
    \Log::info("Fetched HubSpot Contacts ids!");
    \Log::info($contactIds);
    DB::table('dendisoftware_options')->updateOrInsert(
      ['option_name' => 'hubspot_contactIds'],
      [
        'option_value' => json_encode(array_values(array_unique($contactIds))),
        'updated_at'   => $date->toDateTimeString()
      ]
    );
    return ['success' => true];
}

  public function webhookReceived(Request $request)
  {

    $date = Carbon::now();
    $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'hubspot_contactIds')->first();
    if (empty($dbData)) {
      $contactIds = array();
    } else {
      $contactIds = json_decode($dbData->option_value);
    }
    $data = $request->getContent();
    $data = json_decode($data);

    if (!empty($data)) {
      foreach ($data as $key => $value) {
        $contactIds[]  = (int)$value->objectId;
        \Log::info("Fetched HubSpot Contacts id! ". (int)$value->objectId);
        // $checkUpdateBy = $value->changeSource;
        // $propertyName  = $value->propertyName;
        // $propertyValue = $value->propertyValue;
        // if (('CRM_UI' == $checkUpdateBy || 'CONTACTS' == $checkUpdateBy || 'CRM_UI_BULK_ACTION' == $checkUpdateBy) && 'vincere_update' == $propertyName && 'yes' == $propertyValue) {
        //   $contactIds[]  = (int)$value->objectId;
        // }
      }
    }
    // \Log::info($contactIds);
    DB::table('dendisoftware_options')->updateOrInsert(
      ['option_name' => 'hubspot_contactIds'],
      [
        'option_value' => json_encode(array_values(array_unique($contactIds))),
        'updated_at' => $date->toDateTimeString()
      ]
    );
    return ['success' => true];
  }

  // dendi order create webhook received
  public function dendiOrderCreate (Request $request){
 
    $date = Carbon::now();
    $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'created_orderIds')->first();
    if (empty($dbData)) {
      $contactIds = array();
    } else {
      $contactIds = json_decode($dbData->option_value);
    }
    $data = $request->getContent();
    $data = json_decode($data);

    if (!empty($data)) {
      $contactIds[]  = $data->order_code;
      \Log::info("Received created order from dendi! ". $data->order_code);
    }

    DB::table('dendisoftware_options')->updateOrInsert(
      ['option_name' => 'created_orderIds'],
      [
        'option_value' => json_encode(array_values(array_unique($contactIds))),
        'updated_at' => $date->toDateTimeString()
      ]
    );
    return ['success' => true];
  }
  
  // dendi order update webhook received
  public function dendiOrderUpdated (Request $request) {
    
    $date = Carbon::now();
    $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'updated_orderIds')->first();
    if (empty($dbData)) {
      $contactIds = array();
    } else {
      $contactIds = json_decode($dbData->option_value);
    }
    $data = $request->getContent();
    $data = json_decode($data);

    if (!empty($data)) {
      $contactIds[]  = $data->order_code;
      \Log::info("Received updated order from dendi! ". $data->order_code);
    }

    DB::table('dendisoftware_options')->updateOrInsert(
      ['option_name' => 'updated_orderIds'],
      [
        'option_value' => json_encode(array_values(array_unique($contactIds))),
        'updated_at' => $date->toDateTimeString()
      ]
    );
    return ['success' => true];
  }

}