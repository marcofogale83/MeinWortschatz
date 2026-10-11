<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'legal.php';

legal_page_start('Impressum');
$L = $LEGAL;
?>
        <h1>Impressum</h1>
        <p class="muted">Angaben nach Art. 3 Abs. 1 lit. s UWG (Schweiz) und § 5 DDG (Deutschland)</p>

        <h2>Betreiber und verantwortlich für den Inhalt</h2>
        <p>
            <?= legal_v($L['name']) ?><br>
            <?php if ($L['business'] !== ''): ?><?= legal_v($L['business']) ?><br><?php endif; ?>
            <?= legal_v($L['street']) ?><br>
            <?= legal_v($L['city']) ?><br>
            <?= legal_v($L['country']) ?>
        </p>

        <h2>Kontakt</h2>
        <p>
            E-Mail: <?= legal_v($L['email']) ?>
            <?php if ($L['phone'] !== ''): ?><br>Telefon: <?= legal_v($L['phone']) ?><?php endif; ?>
        </p>

        <?php if ($L['uid'] !== ''): ?>
        <h2>Unternehmens-Identifikationsnummer</h2>
        <p><?= legal_v($L['uid']) ?></p>
        <?php endif; ?>

        <h2>Haftungsausschluss</h2>
        <p>
            Die Inhalte dieser Website wurden mit grosser Sorgfalt erstellt. Für die Richtigkeit, Vollständigkeit
            und Aktualität der Inhalte, insbesondere der mit künstlicher Intelligenz erzeugten Übersetzungen,
            Beispielsätze, Korrekturen und Bilder, wird keine Gewähr übernommen. KI-Inhalte können Fehler enthalten.
        </p>
        <p>
            Für Inhalte externer Websites, auf die verwiesen wird, sind ausschliesslich deren Betreiber verantwortlich.
        </p>

        <h2>Urheberrechte</h2>
        <p>
            Texte, Grafiken, Logos und der Programmcode dieser Website sind urheberrechtlich geschützt.
            Eine Verwendung ausserhalb der persönlichen Nutzung der App bedarf der vorherigen Zustimmung des Betreibers.
            Die Schrift Nunito steht unter der SIL Open Font License, die Bibliothek Chart.js unter der MIT-Lizenz.
        </p>

        <h2>Datenschutz</h2>
        <p>Informationen zur Bearbeitung Ihrer Personendaten finden Sie in der <a href="datenschutz">Datenschutzerklärung</a>.</p>
<?php
legal_page_end();
