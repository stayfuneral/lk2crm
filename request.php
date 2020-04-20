<?php

require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";

use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Context;

\Bitrix\Main\Loader::includeModule("crm");
\Bitrix\Main\Loader::includeModule("iblock");

global $USER;
if ($USER->GetLogin() !== "crm_user") die();

$http = new HttpClient;
$deal = new CCrmDeal;
$contact = new CCrmContact;

if (gethostname() !== "hostname_test") {
    // вебхук тестового портала
    $webhook = "https://webhook_test/";
} else {
    // вебхук боевого портала
    $webhook = "https://webhook_real/";
}

// Получение информации о РОП, на которого будут падать сделки

$arDepFilter = [
	"IBLOCK_ID" => 5,
	"ACTIVE" => "Y",
    "GLOBAL_ACTIVE" => "Y",
    "%NAME" => "department_name"
];
$arDepSelect = ["ID", "NAME", "UF_HEAD", "IBLOCK_ID", "IBLOCK_SECTION_ID", "DEPTH_LEVEL"];
$arDepOrder = [
	"DEPTH_LEVEL" => "ASC",
	"SORT" => "ASC"
];

$findDepartment = CIBlockSection::GetList($arDepOrder, $arDepFilter, false, $arDepSelect)->GetNext(true, false);

$assigned = $findDepartment["UF_HEAD"];
$arAssignedUser = CUser::GetByID($assigned)->Fetch();
$assignedUserName = $arAssignedUser["NAME"] . " " . $arAssignedUser["LAST_NAME"];

$response = [];

$request = Context::getCurrent()->getRequest();

if(!empty($request) && $request->isPost() !== false) {

    $inputs = Json::decode($request->getInput());
    $inputs = (object)$inputs;

    $contactName = $inputs->INSURER_FIRSTNAME . " " . $inputs->INSURER_SURNAME . " " . $inputs->INSURER_LASTNAME;

    // Find deal by name
    $arDealOrder = ["ID" => "DESC"];
    $arDealFilter = [
        "TITLE" => $inputs->DEAL_NAME
    ];
    $arDealSelect = ["ID", "TITLE", "STAGE_ID", "CATEGORY_ID", "CONTACT_ID"];



    $findDeal = $deal->GetListEx($arDealOrder, $arDealFilter, $arDealSelect);
    while($findedDeal = $findDeal->Fetch()) {
        
        if(!empty($findedDeal["ID"])) {
            
            $response["deal"] = $findedDeal["ID"];

        }

		if(!empty($findedDeal["CONTACT_ID"])) {
			$response["contact"] = $findedDeal["CONTACT_ID"];
		}

    }
    if(!empty($response["deal"])) {

        $response["result"] = "duplicate";

    } else { // Deal not found

        $stageId = $inputs->POLICY_STATUS === "Оплачен" ? "C28:2" : "C28:1"; 

        $arDealFields = [
            "TITLE" => $inputs->DEAL_NAME,
            "ASSIGNED_BY_ID" => $assigned,
            "OPPORTUNITY" => $inputs->INSPREMIUM,
            "UF_CRM_POLICY_NUMBER" => $inputs->POLICY_NUMBER,
            "UF_CRM_POLICY_LINK" => $inputs->POLICY_LINK,
            "UF_CRM_PRODUCT" => $inputs->PRODUCT,
            "CATEGORY_ID" => 28,
            "STAGE_ID" => $stageId,
            "BEGINDATE" => $inputs->BEGIN_DATE,
            "CLOSEDATE" => $inputs->END_DATE
        ];

        $findContactByPhone = [
            "entity_type" => "CONTACT",
            "type" => "PHONE",
            "values" => [$inputs->INSURER_PHONE]
        ];

        $url = $webhook."crm.duplicate.findbycomm";
        $http->setHeader('Content-Type', 'application/json', true);
        $findContact = $http->post($url, Json::encode($findContactByPhone), true);

        $findedContact = Json::decode($findContact);

        if(!empty($findedContact["result"]["CONTACT"])) {

            $contactId = $findedContact["result"]["CONTACT"][0];

            $arDealFields["CONTACT_ID"] = $contactId;
            $response["contact"] = $contactId;

            

         } else {

            $arContactFields = [
                "NAME" => $contactName,
                "BIRTHDATE" => $inputs->INSURER_BIRTHDAY,
				"ASSIGNED_BY_ID" => $assigned,
                "FM" => [
                    "PHONE" => [
                        "n0" => [
                            "VALUE" => $inputs->INSURER_PHONE
                        ]
                    ]
                ]                        
            ];

            if(!empty($inputs->INSURER_EMAIL)) {
                $arContactFields["FM"]["EMAIL"]["n0"] = ["VALUE" => $inputs->INSURER_EMAIL];
            }

            $createContact = $contact->Add($arContactFields);

            if(intval($createContact) > 0) {
                $response["contact"] = $createContact;
                $arDealFields["CONTACT_ID"] = $createContact;
            } else {
                $response["errors"]["contact"] = $contact->LAST_ERROR;
            }

        }

        $createDeal = $deal->Add($arDealFields);

        if(intval($createDeal) > 0) {
            $response["deal"] = $createDeal;
            $response["result"] = "success";
            $response["assigned_user"] = $assignedUserName;
        } else {
            $response["errors"]["deal"] = $deal->LAST_ERROR;
        }        

    } // end else deal not found

    $encodedResponse = json_encode($response, JSON_UNESCAPED_UNICODE);

    $logParams = [
        "SEVERITY" => !empty($response["errors"]) ? "ERROR" : "INFO",
        "MODULE_ID" => "crm",
        "AUDIT_TYPE_ID" => strtolower("DEAL " . $response["result"]),
        "DESCRIPTION" => $encodedResponse,
        "ITEM_ID" => intval($createDeal) > 0 ? "Deal #{$createDeal}" : "Deal error",
    ];

    CEventLog::Add($logParams);

    echo $encodedResponse;

}

require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php";
