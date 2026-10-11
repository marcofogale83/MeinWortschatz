<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'legal.php';

legal_page_start('Datenschutzerklärung');
$L = $LEGAL;
?>
        <h1>Datenschutzerklärung</h1>
        <p class="muted">Stand: <?= legal_e($L['updated']) ?></p>

        <p>
            Diese Datenschutzerklärung informiert Sie darüber, welche Personendaten wir bearbeiten, wenn Sie
            vokabiq (vokabiq.ch) nutzen, zu welchem Zweck und welche Rechte Sie haben. Sie richtet sich nach dem
            Schweizer Bundesgesetz über den Datenschutz (DSG) und, soweit anwendbar, nach der
            EU-Datenschutz-Grundverordnung (DSGVO).
        </p>

        <h2>1. Verantwortlicher</h2>
        <p>
            <?= legal_v($L['name']) ?><br>
            <?= legal_v($L['street']) ?>, <?= legal_v($L['city']) ?>, <?= legal_v($L['country']) ?><br>
            E-Mail: <?= legal_v($L['email']) ?>
        </p>
        <p>Für alle Fragen zum Datenschutz und zur Ausübung Ihrer Rechte erreichen Sie uns unter dieser E-Mail-Adresse.</p>
        <?php if ($L['eu_rep'] !== ''): ?>
        <p>Vertreter in der EU (Art. 27 DSGVO): <?= legal_v($L['eu_rep']) ?></p>
        <?php endif; ?>

        <h2>2. Welche Daten wir bearbeiten</h2>

        <h3>2.1 Besuch der Website</h3>
        <p>
            Bei jedem Aufruf speichert der Webserver unseres Hosting-Anbieters automatisch technische Angaben in
            Protokolldateien: IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene Datenmenge, Browser
            und Betriebssystem sowie die zuvor besuchte Seite. Diese Daten brauchen wir, um die Website sicher und
            stabil zu betreiben und Angriffe zu erkennen. Sie werden nicht mit Ihrem Konto verknüpft und nach
            <span class="ph">[ANZAHL]</span> Tagen gelöscht.
        </p>

        <h3>2.2 Konto und Anmeldung</h3>
        <p>
            Wenn Sie sich mit Google anmelden, erhalten wir von Google Ihren Namen, Ihre E-Mail-Adresse, die
            Bestätigung, dass die E-Mail-Adresse verifiziert ist, und eine eindeutige Google-Kennung. Wir erhalten
            kein Google-Passwort und keinen Zugriff auf Ihr Google-Konto. Bei Konten mit Benutzername und Passwort
            speichern wir das Passwort nur als sicheren Hash.
        </p>
        <p>Zu Ihrem Konto speichern wir ausserdem Ihr Abo (Gratis, KI oder KI Plus).</p>

        <h3>2.3 Lerndaten</h3>
        <p>
            Damit die App funktioniert, speichern wir die Inhalte, die Sie anlegen oder die beim Lernen entstehen:
            Wörter, Artikel, Pluralformen, Übersetzungen, Beispielsätze, Synonyme, Konjugationen, Themen und Listen,
            generierte Bilder, Lernstand (Punkte, Status, nächstes Übungsdatum) sowie Erstellungs- und
            Änderungszeitpunkte. Daraus berechnen wir Ihre Statistik und Ihr Level.
        </p>

        <h3>2.4 KI-Funktionen (Abo KI und KI Plus)</h3>
        <p>
            Wenn Sie eine KI-Funktion auslösen (zum Beispiel «Mit KI ausfüllen», Satzprüfung oder Deutsch Meister),
            senden wir das betreffende Wort und gegebenenfalls den von Ihnen geschriebenen Satz an einen Anbieter
            von Sprachmodellen. Bei der Bildgenerierung (KI Plus) senden wir das Wort und eine Bildbeschreibung an
            Google. Ihr Name, Ihre E-Mail-Adresse und Ihre Kontokennung werden dabei nicht übermittelt. Bitte
            schreiben Sie in Übungssätze keine persönlichen Angaben über sich oder andere. Die Antworten der KI
            speichern wir bei Ihrem Wort.
        </p>

        <h3>2.5 Bezahlung</h3>
        <p>
            Kostenpflichtige Abos werden über <span class="ph">[ZAHLUNGSANBIETER, z. B. Stripe / Payrexx / Datatrans]</span>
            abgewickelt. Ihre Zahlungsdaten (zum Beispiel Kartennummer) gibt der Zahlungsanbieter nicht an uns weiter.
            Wir erhalten nur die Angaben, die wir zur Freischaltung und Buchhaltung brauchen (Abo, Betrag, Datum,
            Zahlungsstatus, Rechnungsangaben). Buchhaltungsbelege bewahren wir während der gesetzlichen Frist von
            10 Jahren auf.
        </p>

        <h3>2.6 Kontakt per E-Mail</h3>
        <p>Wenn Sie uns schreiben, bearbeiten wir Ihre Angaben, um Ihre Anfrage zu beantworten.</p>

        <h2>3. Cookies und lokale Speicherung</h2>
        <p>
            Wir verwenden keine Werbe-, Analyse- oder Tracking-Cookies und binden keine Tracking-Dienste ein.
            Wir verwenden nur, was für den Betrieb technisch notwendig ist:
        </p>
        <table>
            <tr><th>Name</th><th>Art</th><th>Zweck</th><th>Dauer</th></tr>
            <tr><td>PHPSESSID</td><td>Cookie</td><td>Hält Sie nach der Anmeldung angemeldet</td><td>Bis der Browser geschlossen wird oder Sie sich abmelden</td></tr>
            <tr><td>mw-theme</td><td>Lokaler Speicher</td><td>Merkt sich hellen oder dunklen Modus</td><td>Bis Sie ihn im Browser löschen</td></tr>
            <tr><td>Level-Anzeige</td><td>Lokaler Speicher</td><td>Merkt sich Ihr zuletzt gesehenes Level für die Level-Animation</td><td>Bis Sie ihn im Browser löschen</td></tr>
        </table>
        <p>
            Schriften und Programmbibliotheken (Nunito, Chart.js) liegen auf unserem eigenen Server. Beim Besuch
            werden dafür keine Daten an Google Fonts oder andere Netzwerke für Inhalte übertragen.
        </p>

        <h2>4. Zweck und Rechtsgrundlagen</h2>
        <ul>
            <li>Bereitstellung der App, Ihres Kontos und der gebuchten Abos: Vertragserfüllung (Art. 6 Abs. 1 lit. b DSGVO).</li>
            <li>Sicherer Betrieb, Missbrauchs- und Fehlererkennung, Protokolldateien: berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO).</li>
            <li>Aufbewahrung von Buchhaltungsbelegen: rechtliche Verpflichtung (Art. 6 Abs. 1 lit. c DSGVO).</li>
        </ul>
        <p>Nach dem DSG bearbeiten wir Personendaten nach den Grundsätzen der Rechtmässigkeit, Verhältnismässigkeit und Zweckbindung. Wir verkaufen keine Daten und verwenden sie nicht für Werbung.</p>

        <h2>5. Empfänger und Auftragsbearbeiter</h2>
        <table>
            <tr><th>Anbieter</th><th>Zweck</th><th>Ort</th></tr>
            <tr><td>Infomaniak Network SA, Genf</td><td>Hosting von Website und Datenbank</td><td>Schweiz</td></tr>
            <tr><td>Google (Google Ireland Ltd. und Google LLC)</td><td>Anmeldung mit Google; Bildgenerierung über die Gemini-API (nur KI Plus)</td><td>EU / USA</td></tr>
            <tr><td><span class="ph">[ANBIETER DES SPRACHMODELLS, derzeit DeepSeek-Modell über RapidAPI]</span></td><td>Textfunktionen der KI (nur Abo KI und KI Plus)</td><td><span class="ph">[LAND]</span></td></tr>
            <tr><td><span class="ph">[ZAHLUNGSANBIETER]</span></td><td>Zahlungsabwicklung</td><td><span class="ph">[LAND]</span></td></tr>
        </table>
        <p>
            Diese Anbieter bearbeiten Daten in unserem Auftrag und nur für die genannten Zwecke. Andere Nutzerinnen
            und Nutzer erhalten keine Daten von Ihnen.
        </p>

        <h2>6. Übermittlung ins Ausland</h2>
        <p>
            Einige Anbieter bearbeiten Daten ausserhalb der Schweiz und des EWR, insbesondere in den USA. Google LLC
            ist unter dem EU-US- und dem Swiss-US-Datenschutzrahmen (Data Privacy Framework) zertifiziert. Wo kein
            angemessener Schutz durch einen Angemessenheitsbeschluss besteht, stützen wir die Übermittlung auf die
            Standardvertragsklauseln der EU-Kommission, die vom EDÖB anerkannt sind.
        </p>

        <h2>7. Speicherdauer</h2>
        <ul>
            <li>Konto- und Lerndaten speichern wir, solange Ihr Konto besteht.</li>
            <li>Wenn Sie Ihr Konto löschen, löschen wir sofort Ihr Konto, Ihre Wörter und Bilder.</li>
            <li>Server-Protokolle löschen wir nach <span class="ph">[ANZAHL]</span> Tagen, Datensicherungen des Hosters nach spätestens <span class="ph">[ANZAHL]</span> Tagen.</li>
            <li>Buchhaltungsbelege bewahren wir 10 Jahre auf.</li>
        </ul>

        <h2>8. Ihre Rechte</h2>
        <p>Sie haben das Recht,</p>
        <ul>
            <li>Auskunft über Ihre gespeicherten Personendaten zu erhalten;</li>
            <li>unrichtige Daten berichtigen zu lassen;</li>
            <li>Ihre Daten löschen zu lassen: Sie können Ihr Konto jederzeit selbst unter <a href="profil">Mein Profil</a> löschen;</li>
            <li>Ihre Daten herauszuverlangen: Ihre Wortliste können Sie jederzeit über «CSV exportieren» herunterladen;</li>
            <li>einer Bearbeitung zu widersprechen und die Bearbeitung einschränken zu lassen;</li>
            <li>sich bei einer Aufsichtsbehörde zu beschweren: in der Schweiz beim Eidgenössischen Datenschutz- und Öffentlichkeitsbeauftragten (EDÖB, <a href="https://www.edoeb.admin.ch" target="_blank" rel="noopener noreferrer">edoeb.admin.ch</a>), in der EU bei der Aufsichtsbehörde Ihres Wohnorts.</li>
        </ul>
        <p>Für alle Anfragen genügt eine E-Mail an <?= legal_v($L['email']) ?>. Wir können einen Identitätsnachweis verlangen.</p>

        <h2>9. Datensicherheit</h2>
        <p>
            Die Verbindung ist mit HTTPS verschlüsselt. Passwörter speichern wir nur als Hash, Sitzungs-Cookies sind
            vor dem Zugriff durch Skripte geschützt, und jede Person sieht nur ihre eigenen Lerndaten.
        </p>

        <h2>10. Mindestalter</h2>
        <p>
            vokabiq richtet sich an Personen ab <?= (int)$L['min_age'] ?> Jahren. Jüngere Personen dürfen die App nur
            mit Zustimmung ihrer Eltern oder Erziehungsberechtigten nutzen.
        </p>

        <h2>11. Änderungen</h2>
        <p>
            Wir passen diese Datenschutzerklärung an, wenn sich die App oder die Rechtslage ändert. Es gilt die
            jeweils auf dieser Seite veröffentlichte Fassung.
        </p>
<?php
legal_page_end();
