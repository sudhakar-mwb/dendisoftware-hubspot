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


    $npiId = $request['npi__'];
    $npiId = (string) $npiId;
 
    if (!empty($npiId) && $request['hs_object_id']) {
      // check same npi__ id exist or not
      $getDendiProviderResponse = $this->_getDendiData('api/v1/providers/?npi='.$npiId);

      if ( !empty( $getDendiProviderResponse['response']['count']) && ($getDendiProviderResponse['response']['count'] > 0 ) ) {
        \Log::info('Npi id '. $npiId .' alredy exist. hs_object_id : '. $request['hs_object_id']);
        \Log::info($getDendiProviderResponse);
        return response(json_encode(array('response' => [], 'status' => false, 'message' => "Npi id alredy exist.")));
      }else{

        // fetch hs contact record using emailId
      $hsContactRecord = $this->hubspotSearchContact('hs_object_id', $request['hs_object_id'], 
      ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid']);
      $hsContactRecord = json_decode($hsContactRecord[0]);

      if ($hsContactRecord->total > 0) {
        $hsRecordId         = $hsContactRecord->results[0]->id;
        $hubspotContactInfo = $hsContactRecord->results[0];
        $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));
        if (!empty($hubspotContactInfo) && !empty($hubspotContactInfo->properties->firstname || $hubspotContactInfo->properties->lastname)) {
            // create account in dendi
            // name: String: Unique account name.
            $postData = [
                'name'  => $hubspotContactInfo->properties->firstname.' '.$hubspotContactInfo->properties->lastname,
                'email' => $hubspotContactInfo->properties->email,
                'state' => !empty( $hubspotContactInfo->properties->state) ?  $hubspotContactInfo->properties->state :  "",
            ];
            $createDendiAccResponse = $this->_postDendiData('api/v1/accounts', $postData);

            if (!empty($createDendiAccResponse['response']) && !empty($createDendiAccResponse['response']['uuid'])) {

                \Log::info('Account created in dendi.');
                // Dendi Provider Creation
    
                $providersPostData = [
                    "account_uuids" => [$createDendiAccResponse['response']['uuid']], 
                    "user" => [
                        "first_name" => $hubspotContactInfo->properties->company,
                        "last_name"  => ' - ',
                        "email"      => $hubspotContactInfo->properties->email,
                    ], 
                    "npi"=> $hubspotContactInfo->properties->npi__,
                    "state" => !empty( $hubspotContactInfo->properties->state) ?  $hubspotContactInfo->properties->state :  "",
                ];

                $createDendiProviderResponse = $this->_postDendiData('api/v1/providers', $providersPostData);
                if (!empty($createDendiProviderResponse['response']) && !empty($createDendiProviderResponse['response']['uuid'])) {
                    \Log::info('Provider created in dendi.');
                }else{
                  \Log::info('ERROR during provider creation on dendi. ');
                  \Log::info($createDendiProviderResponse);
                }
                

                try {
                    $newProperties = new SimplePublicObjectInput();
                    $newProperties->setProperties([
                        'account_uuid'  =>  !empty($createDendiAccResponse['response']['uuid']) ? $createDendiAccResponse['response']['uuid'] : "",
                        'provider_uuid' =>  !empty($createDendiProviderResponse['response']['uuid']) ? $createDendiProviderResponse['response']['uuid'] : "",
                    ]);
                    
                    // vincere contact updated, update vincere id in hubspot 
                    $accountUUIDUpdataRes = $hubspot->crm()->contacts()->basicApi()->updateWithHttpInfo($hubspotContactInfo->id, $newProperties);
                    $accountUUIDUpdataRes = json_decode($accountUUIDUpdataRes[0]);
                    if ($accountUUIDUpdataRes->id && !empty($accountUUIDUpdataRes->properties)) {
                        \Log::info('Account & Provider created in dendi and update account_uuid &  provider_uuid in hubspot contact property');
                        \Log::info($accountUUIDUpdataRes->id);
                    } else {
                        \Log::info('Error during dendi account_uuid property update, after dendi contact creation.');
                        \Log::info($accountUUIDUpdataRes);
                    }
                } catch (ApiException $e) {
                    echo "Exception when calling default_api->update: ", $e->getMessage();
                }
            }else{
              \Log::info('ERROR during account creation on dendi. ');
              \Log::info($createDendiAccResponse);
            }
        } else {
            \Log::info('ERROR hubspot contact information not received' . $hubspotContactInfo);
            \Log::info($hubspotContactInfo);
        }
    } else {
        \Log::info("HubSpot contact data not fetched using hsContactID.");
        \Log::info($contactId);
    }
      }
    }else{
      return response(json_encode(array('response' => [], 'status' => false, 'message' => "Npi id not received.")));
    }
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
}