<?php

include('story.php');
try{
	$story = new Story($_GET['story'].'.xml');
	echo('<h2 style="color:green">The story "'.$_GET['story'].'.xml" compiled successfully.</h2>');
}
catch(Exception $ex){
	echo('<h2 style="color:red">The story "'.$_GET['story'].'.xml" contains errors:</h2>');
	echo($ex->getMessage());
}

?>