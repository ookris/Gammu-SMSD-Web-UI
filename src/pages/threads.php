<?php
// Dawna zakładka „Rozmowy” – połączona z „Odebrane”; stare zakładki i linki prowadzą do nowego adresu
redirect(url('inbox', array_filter(['phone' => input('phone')])));
