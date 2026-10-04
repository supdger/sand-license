<?php

/** Configure externally; installation neither writes keys nor grants permissions. */
return [
    'origin' => '', // e.g. https://license.example.com; fixed HTTPS origin without path
    'audience' => 'sand-license',
    'pepper_file' => '', // absolute external HMAC pepper file, owner-readable only
    'signing_key_file' => '', // external JSON signing key; opened only when signing
    'claim_ttl_seconds' => 2592000,
    'public_keys' => [], // public OKP Ed25519 JWKs keyed by kid; no private "d"
    'channels' => [
        // 'store' => [
        //   'product_code' => 'desktop', 'product_id' => '1',
        //   'organization_id' => 1, 'application_id' => 2,
        //   'environment_id' => 3, 'workload_client_id' => 4,
        //   'subject_codes' => ['customer-reference'], 'data_class' => 'commercial.entitlement',
        // ],
    ],
];
