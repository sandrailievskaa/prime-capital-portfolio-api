<?php

// This project is backend-only (CLAUDE.md — no frontend). The file must
// still exist because bootstrap/app.php's withRouting(web: ...) does a bare
// require on this path with no existence check; it stays present and
// intentionally empty rather than being deleted.
