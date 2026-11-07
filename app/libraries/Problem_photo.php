<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Problem_photo {
    public function decode($value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || strlen($value) > 2800000 || !preg_match('/\Adata:image\/(?:jpeg|png);base64,([A-Za-z0-9+\/=]+)\z/D', $value, $match)) throw new DomainException('Photo must be a JPEG or PNG image up to 2 MB.',422);
        $bytes = base64_decode($match[1],TRUE);
        if ($bytes === FALSE || strlen($bytes) > 2097152) throw new DomainException('Photo must be at most 2 MB.',422);
        $size = @getimagesizefromstring($bytes);
        if (!$size || !in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],TRUE) || $size[0] > 4096 || $size[1] > 4096 || $size[0]*$size[1] > 8000000) throw new DomainException('Photo must be a valid JPEG/PNG, at most 4096 pixels per side and 8 megapixels.',422);
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new DomainException('Photo could not be decoded.',422);
        try {
            // Re-encode pixels: remove metadata, embedded payloads and source filenames.
            ob_start();
            try { imagejpeg($image,null,85); $jpeg = ob_get_contents(); } finally { ob_end_clean(); }
        } finally { imagedestroy($image); }
        if (!$jpeg || strlen($jpeg) > 2097152) throw new DomainException('Re-encoded photo exceeds 2 MB; choose a smaller image.',422);
        return $jpeg;
    }
}
