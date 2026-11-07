<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Otrzymano wniosek: :name',
    'applied_body' => 'Otrzymaliśmy wniosek dotyczący :name. Administrator sprawdzi tożsamość organizacji oraz Twoje uprawnienia do jej reprezentowania. Postępy w rozpatrywaniu wniosku możesz śledzić w panelu zarządzania.',
    'admin_subject' => 'Konieczna jest analiza organizacji: :name',
    'admin_body' => 'Wniosek dotyczący :name oczekuje na rozpatrzenie. Otwórz prywatną stronę administracyjną, aby zapoznać się z dowodami i oświadczeniem przedstawiciela.',
    'verify_subject' => 'Sprawdzono :name',
    'verify_body' => 'Weryfikacja tożsamości użytkownika :name została zakończona. Twój profil publiczny jest gotowy: udostępnij jego adres URL i połącz z nim swoje kampanie w panelu zarządzania. Weryfikacja nie oznacza poparcia dla treści kampanii.',
    'reject_subject' => 'Aktualizacja aplikacji: :name',
    'reject_body' => 'Wniosek dotyczący :name nie został zatwierdzony. Zapoznaj się z podaną poniżej przyczyną, zaktualizuj swój profil i, gdy będziesz gotowy, ponownie złóż wniosek.',
    'suspend_subject' => 'Profil zawieszony: :name',
    'suspend_body' => 'Profil publiczny użytkownika :name został zawieszony. Informacje na temat tego statusu znajdziesz w panelu administracyjnym; w razie potrzeby skontaktuj się z pomocą techniczną. Nie powoduje to automatycznego usunięcia Twoich petycji.',
    'restore_subject' => 'Profil przywrócony: :name',
    'restore_body' => 'Zawieszenie konta :name zostało zniesione po rozpatrzeniu sprawy. Otwórz swój profil lub panel administracyjny, aby sprawdzić aktualny status weryfikacji.',
    'donation_subject' => 'Link do darowizny zatwierdzony: :name',
    'donation_body' => 'Zewnętrzny link do darowizn dla :name został zatwierdzony. Fani mogą uzyskać do niego dostęp z zweryfikowanego profilu. Zmiana miejsca przeznaczenia darowizny będzie wymagała ponownej weryfikacji.',
    'action' => 'Otwórz FreeCause',
];

return $strings;
