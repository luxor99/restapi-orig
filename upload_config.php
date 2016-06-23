<?php

return array(
    'mimeTypesAllowed' => array(
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/gif'
    ),
    'maxSize' => '1024k',
    'allowedCharsRegEx' => '/[^A-Za-z0-9]/' // strip all non alpha-numeric characters
);