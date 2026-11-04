<?php
/**
 * Database configuration for the Library Management System.
 *
 * On InfinityFree:
 *   1. Log in to your hPanel (infinityfree.com) -> MySQL Databases.
 *   2. Create a database (e.g. "library") and a user, and assign the user
 *      to the database with ALL PRIVILEGES.
 *   3. Note the values shown there:
 *        Hostname : sqlXXX.epizy.com   (or sqlXXX.infinityfree.com)
 *        Database : epizy_XXXXXXXX_library
 *        Username : epizy_XXXXXXXX
 *        Password : the one you set
 *   4. Copy this file to config/database.php and fill in the values below.
 *      (install.php can also write this file for you automatically.)
 *
 * Every value can also be supplied through environment variables, which
 * take precedence - handy if you do not want credentials in a file:
 *   LIBMEN_DB_HOST, LIBMEN_DB_PORT, LIBMEN_DB_NAME, LIBMEN_DB_USER, LIBMEN_DB_PASS
 */
return [
    'host' => getenv('LIBMEN_DB_HOST') ?: 'sqlXXX.epizy.com', // e.g. sql310.epizy.com
    'port' => getenv('LIBMEN_DB_PORT') ?: '3306',
    'name' => getenv('LIBMEN_DB_NAME') ?: 'epizy_00000000_library',
    'user' => getenv('LIBMEN_DB_USER') ?: 'epizy_00000000',
    'pass' => getenv('LIBMEN_DB_PASS') ?: 'CHANGE_ME',
];
