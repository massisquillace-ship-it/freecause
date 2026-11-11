<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Başvuru alındı: :name',
    'applied_body' => ':name için başvuruyu aldık. Bir yönetici, kuruluşun kimliğini ve sizin bu kuruluşu temsil etme yetkinizi inceleyecektir. Sürecin ilerleyişini yönetim panelinizden takip edebilirsiniz.',
    'admin_subject' => 'Kurumsal inceleme gerekiyor: :name',
    'admin_body' => ':name için yapılan başvuru inceleme aşamasındadır. Kanıtları ve beyan metnini incelemek için özel yönetim sayfasını açın.',
    'verify_subject' => ':name\'nin doğrulanması',
    'verify_body' => ':name için kimlik doğrulama işlemi tamamlandı. Halka açık profiliniz hazır: URL’sini paylaşın ve yönetim panelinizden kampanyalarınızı bu profile bağlayın. Doğrulama işlemi, kampanyalarda yer alan iddiaları onaylamaz.',
    'reject_subject' => 'Uygulama güncellemesi: :name',
    'reject_body' => ':name için yapılan başvuru onaylanmadı. Aşağıdaki gerekçeyi inceleyin, profilinizi güncelleyin ve hazır olduğunuzda başvurunuzu yeniden gönderin.',
    'suspend_subject' => 'Profil askıya alındı: :name',
    'suspend_body' => ':name\'nin genel profili askıya alınmıştır. Durumla ilgili ayrıntılar yönetim panelinizde açıklanmaktadır; yardım için destek ekibiyle iletişime geçin. Bu işlem, dilekçelerinizi otomatik olarak silmez.',
    'restore_subject' => 'Profil geri yüklendi: :name',
    'restore_body' => ':name\'ya uygulanan askıya alma cezası, yapılan incelemenin ardından kaldırılmıştır. Güncel doğrulama durumunu kontrol etmek için profilinizi veya yönetim panelinizi açın.',
    'donation_subject' => 'Bağış bağlantısı onaylandı: :name',
    'donation_body' => ':name için harici bağış bağlantısı onaylandı. Destekçiler, bu bağlantıya doğrulanmış profilden erişebilirler. Hedefin değiştirilmesi durumunda yeni bir inceleme yapılması gerekecektir.',
    'founding_subject' => 'Kuruluş bilgisi güncellendi: :name',
    'founding_body' => ':name için kuruluş onayı güncellendi. Mevcut rozetinizi ve kuruluş durumunuzu kontrol etmek için yönetim panelinizi açın.',
    'owner_reassigned_subject' => 'Atanan kuruluş sahipliği: :name',
    'owner_reassigned_body' => 'Artık FreeCause\'da :name\'un sahibi oldunuz. Yönetim panelinizi açarak kuruluşu, üyelerini ve kampanyalarını inceleyebilirsiniz.',
    'admin_deletion_subject' => 'Kuruluşun kaldırılması talebi: :name',
    'admin_deletion_body' => ':name\'nin sahibi, kuruluşun kaldırılmasını talep etmiştir. Özel yönetim sayfasından bu talebi ve denetim geçmişini inceleyin.',
    'action' => 'FreeCause\'u aç',
];

return $strings;
