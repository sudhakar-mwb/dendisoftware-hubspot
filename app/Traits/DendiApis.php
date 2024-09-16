<?php
namespace App\Traits;

use HubSpot\Factory;
use HubSpot\Client\Crm\Contacts\ApiException;
use HubSpot\Client\Crm\Contacts\Model\SimplePublicObjectInput;
use HubSpot\Client\Crm\Associations\ApiException as AssociationsApiException;
use HubSpot\Client\Crm\Associations\Model\BatchInputPublicObjectId;
use HubSpot\Client\Crm\Associations\Model\PublicObjectId;
use HubSpot\Client\Crm\Companies\ApiException as CompaniesApiException;

trait DendiApis
{
    /**
     * Base url of monday api.
     */
    private $baseUrl = "https://wrenlaboratories.dendisoftware.com/";

    public function _getDendiData ($apiEndPoint){

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->baseUrl.$apiEndPoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: '.env('DENDISOFTWARE_ACCESS_TOKEN')
        ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_errors = curl_error($curl);

        return ['status_code' => $status_code, 'response' => json_decode($response, true), 'errors' => $curl_errors];
    }

    public function _postDendiData ($apiEndPoint, $postData){

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->baseUrl.$apiEndPoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($postData),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: '.env('DENDISOFTWARE_ACCESS_TOKEN')
        ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_errors = curl_error($curl);

        return ['status_code' => $status_code, 'response' => json_decode($response, true), 'errors' => $curl_errors];
    }

    public function _putDendiData ($apiEndPoint, $postData){

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->baseUrl.$apiEndPoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS    => json_encode($postData),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: '.env('DENDISOFTWARE_ACCESS_TOKEN')
        ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        $status_code = curl_getinfcompanyIdo($curl, CURLINFO_HTTP_CODE);
        $curl_errors = curl_error($curl);

        return ['status_code' => $status_code, 'response' => json_decode($response, true), 'errors' => $curl_errors];
    }

    public function hubspotSearchContact($searchBy, $searchValue, $properties = array())
    {

        $hubspot = \HubSpot\Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

        if (empty($searchBy) && empty($searchValue)) {
            return '';
        }

        $filter = new \HubSpot\Client\Crm\Contacts\Model\Filter();
        $filter
            ->setOperator('EQ')
            ->setPropertyName($searchBy)
            ->setValue($searchValue);
        $filterGroup = new \HubSpot\Client\Crm\Contacts\Model\FilterGroup();
        $filterGroup->setFilters([$filter]);

        // \Log::info("Search function" . $filterGroup);

        $searchRequest = new \HubSpot\Client\Crm\Contacts\Model\PublicObjectSearchRequest();
        $searchRequest->setFilterGroups([$filterGroup]);

        if (count($properties) !== 0) {
            // Get specific properties
            $searchRequest->setProperties($properties);
        }

        // @var CollectionResponseWithTotalSimplePublicObject $contactsPage
        \Log::info("hubspotSearchContact " . $searchRequest);
        $contacts = $hubspot->crm()->contacts()->searchApi()->doSearchWithHttpInfo($searchRequest);
        // if ( $contacts[1] == 429) {
        //     sleep(pow(2, 5));
        //     $contacts = $hubspot->crm()->contacts()->searchApi()->doSearchWithHttpInfo($searchRequest);
        // }
        return $contacts;
    }

    public function hubspotContactAssociatedCompanySearch ( $contactId ){

        $client = Factory::createWithAccessToken(env('HUBSPOT_ACCESS_TOKEN'));

        $publicObjectId1 = new PublicObjectId([
            'id' => (string)$contactId
        ]);
        $batchInputPublicObjectId = new BatchInputPublicObjectId([
            'inputs' => [$publicObjectId1],
        ]);
        try {
            $apiResponse = $client->crm()->associations()->batchApi()->read('Contacts', 'Companies', $batchInputPublicObjectId);
            $apiResponse = json_decode($apiResponse);
            if (!empty($apiResponse->results) && !empty($apiResponse->results[0]->to) && !empty($apiResponse->results[0]->to[0]->id) ) {
                try {
                    $properties = ['name'];
                    $companiesResponse = $client->crm()->companies()->basicApi()->getById($apiResponse->results[0]->to[0]->id,$properties, false);
                    return $companiesResponse = json_decode($companiesResponse);
                } catch (CompaniesApiException $e) {
                    echo "Exception when calling basic_api->get_by_id: ", $e->getMessage();
                }
            }
        } catch (AssociationsApiException $e) {
            echo "Exception when calling batch_api->read: ", $e->getMessage();
        }
    }
}
