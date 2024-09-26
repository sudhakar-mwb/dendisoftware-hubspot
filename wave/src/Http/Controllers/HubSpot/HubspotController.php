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
    $npiId = (string) $request['npi__'];
    $providerId = (string) $request['provider_id'];
    $hsObjectId = $request['hs_object_id'];

    // Check if npiId and hs_object_id are provided
    if (!empty($npiId) && $hsObjectId) {
        $getDendiProviderResponse = $this->_getDendiData('api/v1/providers/?npi=' . $npiId);

        // Check if NPI ID already exists in Dendi
        if (!empty($getDendiProviderResponse['response']['count']) && $getDendiProviderResponse['response']['count'] > 0) {
            \Log::info("NPI ID $npiId already exists. hs_object_id: $hsObjectId");
            \Log::info($getDendiProviderResponse);
            \Log::info("NPI ID already exists.");
            // return response()->json(['response' => [], 'status' => false, 'message' => "NPI ID already exists."]);
        } else {
            // Fetch HubSpot contact record using hs_object_id
            $hsContactRecord = $this->hubspotSearchContact('hs_object_id', $hsObjectId, [
                'firstname', 'lastname', 'company', 'npi__', 'email', 'state', 'account_uuid'
            ]);
            $hsContactRecord = json_decode($hsContactRecord[0]);

            if ($hsContactRecord->total > 0) {
                $hubspotContactInfo = $hsContactRecord->results[0];
                $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

                if (!empty($hubspotContactInfo->properties->firstname) || !empty($hubspotContactInfo->properties->lastname)) {
                    // Create account in Dendi
                    $postData = [
                        'name'  => $hubspotContactInfo->properties->firstname . ' ' . $hubspotContactInfo->properties->lastname,
                        'email' => $hubspotContactInfo->properties->email,
                        'state' => $hubspotContactInfo->properties->state ?? "",
                    ];
                    $createDendiAccResponse = $this->_postDendiData('api/v1/accounts', $postData);

                    // Check if account is created successfully in Dendi
                    if (!empty($createDendiAccResponse['response']['uuid'])) {
                        \Log::info('Account created in Dendi.');

                        // Create provider in Dendi
                        $providersPostData = [
                            "account_uuids" => [$createDendiAccResponse['response']['uuid']],
                            "user" => [
                                "first_name" => $hubspotContactInfo->properties->company,
                                "last_name"  => ' - ',
                                "email"      => $hubspotContactInfo->properties->email,
                            ],
                            "npi" => $hubspotContactInfo->properties->npi__,
                            "state" => $hubspotContactInfo->properties->state ?? "",
                        ];
                        $createDendiProviderResponse = $this->_postDendiData('api/v1/providers', $providersPostData);

                        // Check if provider is created successfully in Dendi
                        if (!empty($createDendiProviderResponse['response']['uuid'])) {
                            \Log::info('Provider created in Dendi.');
                        } else {
                            \Log::error('Error during provider creation in Dendi.', $createDendiProviderResponse);
                        }

                        // Update HubSpot contact properties with Dendi account and provider UUIDs
                        try {
                            $newProperties = new SimplePublicObjectInput();
                            $newProperties->setProperties([
                                'account_uuid'  => $createDendiAccResponse['response']['uuid'] ?? "",
                                'provider_uuid' => $createDendiProviderResponse['response']['uuid'] ?? "",
                            ]);

                            $accountUUIDUpdateRes = $hubspot->crm()->contacts()->basicApi()->updateWithHttpInfo($hubspotContactInfo->id, $newProperties);
                            $accountUUIDUpdateRes = json_decode($accountUUIDUpdateRes[0]);

                            if ($accountUUIDUpdateRes->id) {
                                \Log::info('Account & Provider created in Dendi, and updated in HubSpot.', ['id' => $accountUUIDUpdateRes->id]);
                            } else {
                                \Log::error('Error updating HubSpot contact with Dendi UUIDs.', $accountUUIDUpdateRes);
                            }
                        } catch (ApiException $e) {
                            \Log::error('Exception when updating HubSpot contact:', ['message' => $e->getMessage()]);
                        }
                    } else {
                        \Log::error('Error during account creation in Dendi.', $createDendiAccResponse);
                    }
                } else {
                    \Log::error('HubSpot contact information not received.', $hubspotContactInfo);
                }
            } else {
                \Log::error('HubSpot contact data not fetched using hs_object_id.', ['hs_object_id' => $hsObjectId]);
            }
        }
    } elseif (!empty($providerId) && $hsObjectId) {
        // Fetch provider details from Dendi using provider ID
        $getDendiProviderResponse = $this->_getDendiData('api/v1/providers/?uuid=' . $providerId);

        if (!empty($getDendiProviderResponse['response']['count']) && $getDendiProviderResponse['response']['count'] > 0) {
            // Fetch HubSpot contact record using hs_object_id
            $hsContactRecord = $this->hubspotSearchContact('hs_object_id', $hsObjectId, [
                'firstname', 'lastname', 'company', 'npi__', 'email', 'state', 'account_uuid'
            ]);
            $hsContactRecord = json_decode($hsContactRecord[0]);

            if ($hsContactRecord->total > 0) {
                $hubspotContactInfo = $hsContactRecord->results[0];
                $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

                if (!empty($hubspotContactInfo->properties->firstname) || !empty($hubspotContactInfo->properties->lastname)) {
                    // Create account in Dendi
                    $postData = [
                        'name'  => $hubspotContactInfo->properties->firstname . ' ' . $hubspotContactInfo->properties->lastname,
                        'email' => $hubspotContactInfo->properties->email,
                        'state' => $hubspotContactInfo->properties->state ?? "",
                    ];

                    $createDendiAccResponse = $this->_postDendiData('api/v1/accounts', $postData);

                    // Check if account is created successfully in Dendi
                    if (!empty($createDendiAccResponse['response']['uuid'])) {
                        \Log::info('Account created in Dendi.');

                        // Associate provider with Dendi account
                        $providersPostData = [
                            "account_uuids" => [$createDendiAccResponse['response']['uuid']],
                            "npi" => $providerId,
                        ];
                        $createDendiProviderResponse = $this->_putDendiData('api/v1/providers/' . $providerId, $providersPostData);

                        // Check if provider is successfully associated with the account in Dendi
                        if (!empty($createDendiProviderResponse['response']['uuid'])) {
                            \Log::info('Provider associated with Dendi.');
                        } else {
                            \Log::error('Error during provider association with Dendi.', $createDendiProviderResponse);
                        }

                        // Update HubSpot contact properties with Dendi account and provider UUIDs
                        try {
                            $newProperties = new SimplePublicObjectInput();
                            $newProperties->setProperties([
                                'account_uuid'  => $createDendiAccResponse['response']['uuid'] ?? "",
                                'provider_uuid' => $providerId ?? "",
                            ]);

                            $accountUUIDUpdateRes = $hubspot->crm()->contacts()->basicApi()->updateWithHttpInfo($hubspotContactInfo->id, $newProperties);
                            $accountUUIDUpdateRes = json_decode($accountUUIDUpdateRes[0]);

                            if ($accountUUIDUpdateRes->id) {
                                \Log::info('Account & Provider created in Dendi, and updated in HubSpot.', ['id' => $accountUUIDUpdateRes->id]);
                            } else {
                                \Log::error('Error updating HubSpot contact with Dendi UUIDs.', $accountUUIDUpdateRes);
                            }
                        } catch (ApiException $e) {
                            \Log::error('Exception when updating HubSpot contact:', ['message' => $e->getMessage()]);
                        }
                    } else {
                        \Log::error('Error during account creation in Dendi.', $createDendiAccResponse);
                    }
                } else {
                    \Log::error('HubSpot contact information not received.', $hubspotContactInfo);
                }
            } else {
                \Log::error('HubSpot contact data not fetched using hs_object_id.', ['hs_object_id' => $hsObjectId]);
            }
        } else {
            \Log::error("Provider data not found for Provider ID: $providerId.");
        }
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