<?php
// Firebase web configuration identifies this project; it is not an administrator credential.
return [
    'project_id' => getenv('FIREBASE_PROJECT_ID') ?: 'campus-lost-and-found-dfb32',
    'api_key' => getenv('FIREBASE_API_KEY') ?: 'AIzaSyCswJjRP_hqeeKY3lZPLopK_sXCW6X1c0g',
];
