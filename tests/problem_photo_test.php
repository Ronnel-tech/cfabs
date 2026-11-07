<?php
define('PREVENT_DIRECT_ACCESS',TRUE);
require dirname(__DIR__).'/app/libraries/Problem_photo.php';
$checks=0;
function photo_check(bool $ok,string $name):void { global $checks;if(!$ok)throw new RuntimeException('Failed: '.$name);$checks++; }
$photo=new Problem_photo();photo_check($photo->decode(null)===null,'photo optional');
foreach([FALSE,[],42,'','data:image/svg+xml;base64,'.base64_encode('<svg/>'),'data:image/png;base64,broken','data:image/png;base64,'.base64_encode('not an image'),str_repeat('A',2800001)] as $invalid) {
    try{$photo->decode($invalid);throw new RuntimeException('Invalid photo accepted');}catch(DomainException $error){photo_check($error->getCode()===422,'invalid input rejected');}
}
$image=imagecreatetruecolor(4097,1);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
try{$photo->decode('data:image/png;base64,'.base64_encode($bytes));throw new RuntimeException('Oversize dimensions accepted');}catch(DomainException $error){photo_check($error->getCode()===422,'dimension guard before decoding');}
$image=imagecreatetruecolor(3,2);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
$jpeg=$photo->decode('data:image/png;base64,'.base64_encode($bytes.'SOURCE_METADATA'));
photo_check(str_starts_with($jpeg,"\xff\xd8") && !str_contains($jpeg,'SOURCE_METADATA'),'re-encoding strips appended metadata');
$size=getimagesizefromstring($jpeg);photo_check($size[0]===3 && $size[1]===2 && $size[2]===IMAGETYPE_JPEG,'image dimensions preserved');
echo "Passed {$checks} private photo checks.\n";
