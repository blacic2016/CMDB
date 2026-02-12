<?php
// temp_list_tables.php
require_once  '../src/helpers.php';
$tables = listSheetTables();
echo json_encode($tables);
