<?php 

# basename, pathinfo
# https://stackoverflow.com/questions/45268499/php-basename-and-pathinfo-with-multibytes-utf-8-file-names
setlocale(LC_ALL,'C.UTF-8');

# The directory containing the php5-markdown wiki code
$appRoot = '/home/user/projects/php5-markdown/';

$config = array(
	# Directory to store the markdown pages
	'docDir'      => $appRoot . 'pages/',
	
	# Default page name
	'defaultPage' => 'index'

	# nginx PATH_INFO parsing needs an explicit .php extension
	'baseUrl'     => '/markdown/index.php/',

);


# And off we go...
require_once $appRoot . 'markdown-wiki.php';

?>
