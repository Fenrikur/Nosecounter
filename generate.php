<?php
/**
 * Example for using the Nosecounter class to generate a HTML output file.
 *
 * (c) 2024 by Fenrikur <nosecounter(at)fenrikur.de>
 */

 namespace Eurofurence\Nosecounter;

 require __DIR__ . '/vendor/autoload.php';
 require "Nosecounter.php";
 
 use DateTime;
 use DateTimeZone;

echo $nosecounter->setApiUrl('%insert_your_api_endpoint_url_here%')
    ->setApiToken('%insert_your_api_token_here%')
    ->setYear(2017)
    ->setRegistrationsStart(new DateTime('2016-12-26 00:00:00', new DateTimeZone('UTC')))
    ->setRegistrationsEnd(new DateTime('2017-08-31 00:00:00', new DateTimeZone('UTC')))
    ->generate('.' . DIRECTORY_SEPARATOR . 'view.php', '.' . DIRECTORY_SEPARATOR . 'view.html');
