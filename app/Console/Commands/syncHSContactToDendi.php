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
            ['firstname', 'lastname', 'company', 'npi__', 'email', 'state','account_uuid']);
            $hsContactRecord = json_decode($hsContactRecord[0]);

            // fetch hs contact with associated company record using contactId
            $hsCompanyRecord = $this->hubspotContactAssociatedCompanySearch($contactId);

            if ($hsContactRecord->total > 0) {
                $hsRecordId         = $hsContactRecord->results[0]->id;
                $hubspotContactInfo = $hsContactRecord->results[0];
                $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

                /*
                // create or update account in dendi
                if (!empty($hubspotContactInfo->properties->account_uuid)) {
                    // account alredy created in dendi. need to update the account property in dendi

                    // frist search the account in dendi
                    $getDendiAccResponse = $this->_getDendiData('api/v1/accounts/'.$hubspotContactInfo->properties->account_uuid);
                    
                    if (!empty($getDendiAccResponse['response']) && !empty($getDendiAccResponse['response']['uuid'])) {
                        if ((string)($getDendiAccResponse['response']['uuid']) == (string)($hubspotContactInfo->properties->account_uuid)) {
                            // update the account property in dendi
                            $postData = [
                                'state' => !empty($hubspotContactInfo->properties->state) ? $hubspotContactInfo->properties->state : '',
                            ];
                            $updateDendiAccResponse = $this->_putDendiData('api/v1/accounts/'.$hubspotContactInfo->properties->account_uuid, $postData);
                            if (!empty($updateDendiAccResponse['response']) && !empty($updateDendiAccResponse['response']['uuid'])) {
                                \Log::info('Account alredy exist in dendi. Dendi account property updated');
                            }else{
                                \Log::info('Account alredy exist in dendi. But Dendi account property not updated. received error : ');
                                \Log::info($updateDendiAccResponse);
                            }
                        }else{
                            \Log::info('Dendi account uuid and hubspot contact account_uuid not matched.');
                        }
                    }else{
                        \Log::info('Serach account in dendi using account_uuid from hs contact. Data Not Found ');
                        \Log::info($getDendiAccResponse);
                    }
                }
                */
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
                        if (!empty($hsCompanyRecord->properties) && !empty($hsCompanyRecord->properties->name)) {
                            $providersPostData = [
                                "account_uuids" => [$createDendiAccResponse['response']['uuid']], 
                                "user" => [
                                    "first_name" => $hsCompanyRecord->properties->name,
                                    "last_name"  => ' - ',
                                    "email"      => $hubspotContactInfo->properties->email,
                                ], 
                                "npi"=> $hubspotContactInfo->properties->npi__,
                                "state" => !empty( $hubspotContactInfo->properties->state) ?  $hubspotContactInfo->properties->state :  $hubspotContactInfo->properties->state,
                            ];
                            $createDendiProviderResponse = $this->_postDendiData('api/v1/providers', $providersPostData);
                            if (!empty($createDendiProviderResponse['response']) && !empty($createDendiProviderResponse['response']['uuid'])) {
                                \Log::info('Provider created in dendi.');
                            }
                        }

                        try {
                            $newProperties = new SimplePublicObjectInput();
                            $newProperties->setProperties([
                                'account_uuid'  =>  $createDendiAccResponse['response']['uuid'],
                                'provider_uuid' =>  $createDendiProviderResponse['response']['uuid'],
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
                    }
                } else {
                    \Log::info('ERROR hubspot contact information not received' . $hubspotContactInfo);
                    \Log::info($hubspotContactInfo);
                }
            } else {
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
