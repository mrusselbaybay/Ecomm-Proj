<?php

return [
    'policy_version' => env('LEGAL_POLICY_VERSION', '[POLICY_VERSION]'),
    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '[EFFECTIVE_DATE]'),
    'dpo_name' => env('DPO_NAME', 'Joshua Llanto'),
    'dpo_email' => env('DPO_EMAIL', 'llantojoshua73@gmail.com'),
    'business_address' => env('BUSINESS_ADDRESS', '[BUSINESS_ADDRESS]'),
    'npc_registration_no' => env('NPC_REGISTRATION_NO', '[NPC_REGISTRATION_NO]'),
    'trustmark_no' => env('TRUSTMARK_NO', '[TRUSTMARK_NO]'),
    'bir_tin' => env('BIR_TIN', '[BIR_TIN]'),
    'dti_odr_url' => env('DTI_ODR_URL', 'https://consumercare.dti.gov.ph/'),
];
