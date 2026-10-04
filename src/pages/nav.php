<?php
// Fragment menu odświeżany przez htmx co 15 s (liczniki, wskaźnik usługi i modemu)
echo view('partials/nav', ['nav' => input('cur'), 'status' => Status::indicator()]);
