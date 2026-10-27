=== BaseLinker Synchronizer ===

Синхронизацию можно запускать по http-запросу
?action=blkSynchronizer&method=synchronize

либо через shell
cd wp-content/plugins/blk-synchronizer/cli
php -f start-synchronize.php


удаление всех продутов только через shell
cd wp-content/plugins/blk-synchronizer/cli
php -f delete-all-products.php


== Changelog ==

= 3.2.1 (2026-10-27) =
* Opisy produktów: clean_description() używa DescriptionCleaner z Best Market
  Core (gdy wtyczka jest aktywna) i usuwa z opisów z BaseLinkera śmieci z Worda
  i marketplace'ów (style, spany, klasy Mso, komentarze, zagnieżdżone
  div/section), zostawiając akapity, pogrubienia i listy. Te same reguły
  oczyściły już zapisane opisy (`wp bm descriptions clean`), więc
  has_product_changed() porównuje to samo z tym samym i niezmieniony produkt nie
  jest nadpisywany przy każdej synchronizacji. Bez Best Market Core działa jak
  dotąd (stare wyrażenie regularne).

= 3.2.0 (2026-09-09) =
* Ceny promocyjne z BaseLinkera. Nowe ustawienie "Price Group ID (przed
  promocją)": gdy cena z tej grupy cenowej jest wyższa niż cena z grupy
  domyślnej, produkt dostaje w WooCommerce regular_price = cena "przed
  promocją" i sale_price = cena domyślna. W przeciwnym razie cena
  promocyjna jest czyszczona. 0 = obsługa promocji wyłączona.
* Kategoria "Promocje". Nowe ustawienie "Promo Category ID": produkt z
  aktywną ceną promocyjną dostaje ten term product_cat dodatkowo (bez
  usuwania innych kategorii), i traci go, gdy promocja się kończy.
  0 = wyłączone.
* Cena promocyjna jest uwzględniona w porównaniu produktów
  (has_product_changed), włącznie z przejściami null <-> wartość, więc
  start i koniec promocji są wychwytywane przez synchronizację.
* Cena promocyjna jest propagowana na wszystkie tłumaczenia WPML.
* Historia cen Omnibus: 30-dniowa krocząca historia faktycznie
  naliczanej ceny per produkt (_blk_omnibus_price_history) plus
  zapamiętane minimum (_blk_omnibus_lowest_30d). Zapisywana przy
  tworzeniu/aktualizacji, dla każdego tłumaczenia WPML oraz dla
  wszystkich produktów podczas prepareOldProducts(), żeby promocje
  trwające przed wdrożeniem tej funkcji też miały realną historię i
  zostały skategoryzowane retroaktywnie.
* Nowy widget Elementora "BLK Omnibus Price" (szablon produktu
  pojedynczego oraz Loop Item). Pokazuje komunikat o cenie przed
  obniżką, tylko gdy cena promocyjna jest aktywna. Kwota brana jest
  zawsze z ceny podstawowej (regular price) - nie z ceny promocyjnej i
  nie z historii cen. Ma własne nadpisanie etykiety oraz obsługę WPML
  String Translation z automatycznie zasianym tłumaczeniem ukraińskim.
* Panel Omnibus (tylko do odczytu: minimum z 30 dni + historia) w
  edytorze produktu WooCommerce, pod polem ceny promocyjnej.
* Trwałe podsumowanie "Last synchronization" na pulpicie wtyczki -
  czas trwania, dodane, zaktualizowane, usunięte, zignorowane. W
  przeciwieństwie do toasta i banera zostaje widoczne do startu
  następnej synchronizacji. Dane pochodzą z nowego pola stats w
  .sync-progress.json.

= 3.1.1 (2026-08-26) =
* Naprawiony licznik "Next Scheduled Sync": po dojściu do zera pokazywał
  "Running now..." bez końca, bo docelowy timestamp renderowany był raz
  po stronie serwera. Licznik odpytuje teraz blk_sync_status (throttling
  10s, single-flight) i sam wznawia się na następne wykonanie, bez
  przeładowania strony.
* Odpowiedź AJAX blk_sync_status zwraca next_sync (timestamp) i
  next_sync_display (H:i:s w strefie czasowej witryny).
* Plugin Update Checker wskazuje na afisza/blk-synchronizer (branch
  main) - stamtąd WordPress pobiera aktualizacje.
* GitHub Actions CI: sprawdzanie składni PHP i JS na push/PR; push tagu
  v* automatycznie tworzy GitHub Release.

= 3.1 (2026-08-25) =
* Naprawiony bezpowrotnie tracony event crona ("Not scheduled").
  Zdarzenie blkSynchronizer było planowane wyłącznie w
  change_cron_interval(), czyli tylko przy zmianie wartości interwału -
  brak hooka aktywacji i brak ścieżki odzysku oznaczał, że raz utracony
  event (reinstalacja, deaktywacja, czyszczenie crona) cicho zatrzymywał
  automatyczną synchronizację na zawsze.
* BlkHooks::maybe_schedule_sync() - samonaprawa na init, odtwarza
  brakujące zdarzenie cykliczne.
* change_cron_interval() obsługuje pierwszy zapis/brak starej wartości i
  przeplanowuje przy każdej realnej zmianie interwału.
* register_activation_hook planuje zdarzenie przy aktywacji,
  register_deactivation_hook je czyści.
* Wraz z tą wersją trafił do repozytorium cały kod 2.12 -> 3.0, wcześniej
  wdrażany bezpośrednio na serwer i nigdy niewypchnięty: przebudowa
  panelu administracyjnego, zapytania API w porcjach, pliki blokady/stop,
  rotacja logów, raportowanie postępu.

= 2.x i wcześniejsze =
* Historia w git ogranicza się do samych numerów wersji bez opisu zmian
  (2.4.2 - 2.12, 2024). Szczegóły: git log.
