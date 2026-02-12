<?php
/**
 * Redirige al usuario a la carpeta public de forma relativa.
 * Esto funciona sin importar si la carpeta se llama Sonda o CMDB.
 */
header('Location: public/');
exit();
