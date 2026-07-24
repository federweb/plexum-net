<?php
// Media folder guard — prevents directory listing. Files are browsed through
// the File Manager (auth-gated) or served by api.php.
http_response_code(403);
