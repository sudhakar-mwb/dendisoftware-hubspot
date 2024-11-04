<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DB;
use Carbon\Carbon;
use HubSpot\Factory;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput;
use App\Traits\DendiApis;

class syncHSContactToDendi extends Command
{
    use DendiApis;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:syncHSContactToDendi';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // this function is not required. need to remove it
        $date   = Carbon::now();
        $dbData = DB::table('dendisoftware_options')->select('option_value')->where('option_name', 'hubspot_contactIds')->first();
        $count  = 0;

        if (empty($dbData)) {
            \Log::info("Index not found");
            return Command::SUCCESS;
        } else {
            $dbData = json_decode($dbData->option_value);
        }
        if (empty($dbData)) {
            \Log::info("No contact found to sync");
            return Command::SUCCESS;
        } else {
            $contactIds = $dbData;
        }

        foreach ($contactIds as $key => $contactId) {

            $hsContactId = $contactId;
            $count++;
            $contactProperties = array();
            if ($count > 5) {
                \Log::info("5 contacts synced");
                break;
            }

            // fetch hs contact record using emailId
            $hsContactRecord = $this->hubspotSearchContact('hs_object_id', $contactId, 
            ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid','alternate_id']);
            $hsContactRecord = json_decode($hsContactRecord[0]);


            if ($hsContactRecord->total > 0) {
                $npiId       = (string)$hsContactRecord->results[0]->properties->npi__;
                $alternateId  = (string)$hsContactRecord->results[0]->properties->alternate_id;
                $hsObjectId  = $hsContactRecord->results[0]->properties->hs_object_id;


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
                } elseif (!empty($alternateId) && $hsObjectId) {
                    // Fetch provider details from Dendi using provider ID
                    $getDendiProviderResponse = $this->_getDendiData('api/v1/providers/?alternate_id=' . $alternateId);

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
                                        "alternate_id"  => $alternateId,
                                    ];
                                    $createDendiProviderResponse = $this->_putDendiData('api/v1/providers/' . $getDendiProviderResponse['response']['results'][0]['uuid'], $providersPostData);

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
                                            'provider_uuid' => $getDendiProviderResponse['response']['results'][0]['uuid'] ?? "",
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
                        \Log::error("Provider data not found for Alternate ID: $alternateId.");
                    }
                }


            }else{
                \Log::info("HubSpot contact data not fetched using hsContactID.");
                \Log::info($contactId);
            }

            \Log::info("HubSpot to dendi data map now unset id.");
            \Log::info($contactIds[$key]);

            unset($contactIds[$key]);
        }
        DB::table('dendisoftware_options')->updateOrInsert(
            ['option_name' => 'hubspot_contactIds'],
            [
                'option_value' => json_encode(array_values(array_unique($contactIds))),
                'updated_at' => $date->toDateTimeString()
            ]
        );
    }
}
