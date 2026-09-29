<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$assetVersion = static function (string $relative): string {
    $path = __DIR__ . '/' . ltrim($relative, '/');
    return is_file($path) ? (string) filemtime($path) : '20260928-1225';
};
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-build" content="20260929-0955">
  <title>Gestione Commesse e Produzione</title>
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="assets/style.css?v=<?= htmlspecialchars($assetVersion('assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<header class="topbar">
  <div>
    <div class="eyebrow" id="headerCompanyName">Sistema gestionale</div>
    <h1>Gestione Commesse e Produzione</h1>
    <p>Controllo operativo di commesse, macchine, lavorazioni e costi</p>
  </div>
  <div class="top-actions">
    <span id="lastRefresh" class="muted-light">Mai aggiornato</span>
    <button id="btnRefresh" class="secondary">Aggiorna ora</button>
  </div>
</header>

<nav class="tabs" aria-label="Navigazione principale">
  <button data-tab="dashboard" class="active">Dashboard</button>
  <button data-tab="jobs">Commesse</button>
  <button data-tab="maestro">Bordatrice</button>
  <button data-tab="reports">Report</button>
  <button data-tab="clients">Clienti</button>
  <button data-tab="folder">Cartella lavori</button>
  <button data-tab="scheduling">Scheduling</button>
  <button data-tab="costs">Costi</button>
  <button data-tab="company">Dati azienda</button>
  <button data-tab="settings">Impostazioni</button>
</nav>

<main>
  <section id="tab-dashboard" class="tab active">
    <div class="page-head">
      <div><h2>Dashboard produzione</h2><p>Stato macchine, commesse attive e indicatori principali.</p></div>
      <button id="btnLiveRefresh">Aggiorna macchine</button>
    </div>
    <div class="grid cards" id="dashboardCards"></div>
    <h3 class="section-title">Macchine</h3>
    <div id="machineCards" class="machine-grid"></div>
    <div class="panel">
      <div class="panel-head"><div><h3>Commesse recenti</h3><p class="muted">Ordinate dalla più recente alla meno recente.</p></div></div>
      <div class="table-wrap"><table id="dashboardJobsTable"></table></div>
    </div>
    <details class="page-help"><summary>Guida Dashboard</summary><p>Controlla lo stato delle macchine, le commesse più recenti e gli indicatori principali. Usa “Aggiorna macchine” per forzare una lettura immediata delle macchine connesse.</p></details>
  </section>

  <section id="tab-jobs" class="tab">
    <div class="page-head"><div><h2>Commesse</h2><p>Crea, cerca, invia e controlla lo stato delle commesse.</p></div></div>
    <div class="panel bulk-panel">
      <div class="row wrap">
        <strong>Azioni multiple</strong>
        <label>Bordatrice<select id="bulkMachine"></select></label>
        <button id="btnBulkOpen">Apri selezionate su macchina</button>
        <button id="btnBulkClose" class="danger">Chiudi selezionate</button>
        <span id="bulkCount" class="muted">0 selezionate</span>
      </div>
      <div id="bulkResult" class="inline-result"></div>
    </div>
    <div class="panel two-col">
      <form id="jobForm" class="form">
        <h3>Nuova / modifica commessa</h3>
        <input type="hidden" name="id">
        <label>Cliente *<select name="client_id" required></select></label>
        <label>Tipo lavoro *<select name="job_type_id" required></select></label>
        <label>Macchina<select name="machine_id"></select></label>
        <label>Codice commessa *<input name="job_code" required maxlength="80"></label>
        <label>Titolo *<input name="title" required maxlength="180"></label>
        <label>Descrizione<textarea name="description"></textarea></label>
        <div class="row">
          <label>Stato<select name="status">
            <option value="bozza">Bozza</option><option value="aperta">Aperta</option><option value="in_lavorazione">In lavorazione</option><option value="chiusa">Chiusa</option><option value="archiviata">Archiviata</option>
          </select></label>
          <label>Inizio<input name="start_date" type="date"></label>
          <label>Scadenza<input name="due_date" type="date"></label>
        </div>
        <label>Note<textarea name="notes"></textarea></label>
        <div class="row"><button type="submit">Salva commessa</button><button type="button" class="secondary" id="btnJobReset">Nuova</button></div>
      </form>
      <div>
        <div class="toolbar">
          <input id="jobSearch" placeholder="Cerca codice, titolo o cliente">
          <select id="jobStatusFilter"><option value="">Tutti gli stati</option><option value="bozza">Bozza</option><option value="aperta">Aperta</option><option value="in_lavorazione">In lavorazione</option><option value="chiusa">Chiusa</option><option value="archiviata">Archiviata</option></select>
          <button id="btnJobSearch" class="secondary">Cerca</button>
        </div>
        <div class="table-wrap"><table id="jobsTable"></table></div>
      </div>
    </div>
    <details class="page-help"><summary>Guida Commesse</summary><p>Crea e modifica le commesse, filtra l’elenco e seleziona più commesse per inviarle alla bordatrice. Lo stato viene aggiornato anche dalle sincronizzazioni automatiche quando la macchina è raggiungibile.</p></details>
  </section>

  <section id="tab-maestro" class="tab">
    <div class="page-head"><div><h2>Bordatrice Maestro Active</h2><p>Comandi manuali, stato e importazione produzione.</p></div></div>
    <div class="panel">
      <div class="row wrap">
        <label>Macchina<select id="maestroMachine"></select></label>
        <label>Commessa<select id="maestroJob"></select></label>
        <button id="btnMaestroStatus">Leggi stato</button>
        <button id="btnMaestroInfo" class="secondary">Info macchina</button>
        <button id="btnOrderOpen">Apri</button>
        <button id="btnOrderActivate">Attiva</button>
        <button id="btnOrderClose" class="danger">Chiudi</button>
      </div>
      <div id="maestroHumanStatus" class="status-summary"></div>
      <details><summary>Risposta tecnica API</summary><pre id="maestroOutput" class="output">Nessun dato.</pre></details>
    </div>
    <div class="panel">
      <h3>Import produzione manuale</h3>
      <p class="muted">Lo scheduler importa automaticamente i dati. Usa questa funzione per recuperi storici o verifiche.</p>
      <div class="row wrap">
        <label>Da<input id="prodFrom" type="datetime-local"></label>
        <label>A<input id="prodTo" type="datetime-local"></label>
        <label>Limite pagina<input id="prodLimit" type="number" min="1" max="500" value="100"></label>
        <button id="btnImportProduction">Importa</button>
      </div>
      <div class="table-wrap"><table id="productionTable"></table></div>
    </div>
    <details class="page-help"><summary>Guida Bordatrice</summary><p>Usa questa pagina per comandi manuali, verifica dello stato e recuperi storici. L’importazione con una commessa selezionata filtra i dati: non forza più associazioni incompatibili.</p></details>
  </section>

  <section id="tab-reports" class="tab">
    <div class="page-head"><div><h2>Report commessa</h2><p>Produzione, tempi, consumo bordo e costo configurato.</p></div></div>
    <div class="panel">
      <div class="report-controls">
        <label class="report-search-label">Cerca commessa o cliente
          <input id="reportSearch" autocomplete="off" placeholder="Digita codice commessa, titolo o cliente">
        </label>
        <input type="hidden" id="reportJobId">
        <div id="reportSearchResults" class="autocomplete-list" hidden></div>
        <div class="row wrap">
          <button id="btnReportLoad">Genera report</button>
          <button id="btnReportPrint" class="secondary" type="button" disabled>Stampa / salva PDF</button>
        </div>
      </div>
      <div id="reportDocument">
        <div id="reportCompanyHeader" class="report-company-header"></div>
        <div id="reportHeader" class="report-header empty-state">Cerca e seleziona una commessa.</div>
        <div id="reportCards" class="grid cards"></div>
        <div id="reportCosts"></div>
        <h3>Consumo bordo</h3>
        <div class="table-wrap"><table id="reportEdgesTable"></table></div>
        <h3>Dettaglio produzione</h3>
        <div class="table-wrap"><table id="reportProductionTable"></table></div>
      </div>
    </div>
    <details class="page-help"><summary>Guida Report</summary><p>Digita parte del codice commessa, del titolo o del cliente e seleziona il risultato proposto. Genera il report, quindi usa “Stampa / salva PDF” per stampare o salvare il documento in PDF con l’intestazione aziendale configurata.</p></details>
  </section>

  <section id="tab-clients" class="tab">
    <div class="page-head"><div><h2>Clienti</h2><p>Anagrafica clienti collegata alle commesse.</p></div></div>
    <div class="panel two-col">
      <form id="clientForm" class="form">
        <h3>Cliente</h3><input type="hidden" name="id">
        <label>Codice<input name="code" maxlength="40"></label><label>Ragione sociale *<input name="company_name" required maxlength="180"></label>
        <label>P. IVA<input name="vat_number" maxlength="40"></label><label>Codice fiscale<input name="tax_code" maxlength="40"></label>
        <label>Email<input name="email" type="email" maxlength="180"></label><label>Telefono<input name="phone" maxlength="80"></label>
        <label>Indirizzo<input name="address" maxlength="255"></label>
        <div class="row"><label>Città<input name="city"></label><label>Prov.<input name="province"></label><label>CAP<input name="postal_code"></label></div>
        <label>Note<textarea name="notes"></textarea></label><label class="check"><input name="active" type="checkbox" checked> Attivo</label>
        <div class="row"><button type="submit">Salva cliente</button><button type="button" class="secondary" id="btnClientReset">Nuovo</button></div>
      </form>
      <div><div class="toolbar"><input id="clientSearch" placeholder="Cerca cliente"><button id="btnClientSearch" class="secondary">Cerca</button></div><div class="table-wrap"><table id="clientsTable"></table></div></div>
    </div>
    <details class="page-help"><summary>Guida Clienti</summary><p>Gestisci le anagrafiche clienti utilizzate nelle commesse. La ricerca lavora su ragione sociale, codice, partita IVA ed email.</p></details>
  </section>

  <section id="tab-folder" class="tab">
    <div class="page-head"><div><h2>Cartella lavori</h2><p>File rilevati dalla macchina misure tramite cartella SMB.</p></div></div>
    <div class="panel">
      <div class="row wrap"><label>Macchina<select id="folderMachine"></select></label><button id="btnScanFiles">Scansiona</button><input id="fileSearch" placeholder="Cerca file"><select id="fileAssignedFilter"><option value="0">Nuovi non associati</option><option value="1">Associati</option><option value="">Tutti</option></select><button id="btnFilesRefresh" class="secondary">Aggiorna lista</button></div>
      <div class="row wrap"><label>Associa a commessa<select id="assignJob"></select></label><button id="btnAssignFile">Associa</button><button id="btnUnassignFile" class="secondary">Rimuovi associazione</button><button id="btnFolderJobCreateToggle" class="secondary" type="button">Crea nuova commessa</button></div>
      <form id="folderJobCreateForm" class="form compact inline-create" hidden>
        <h3>Nuova commessa da cartella lavori</h3>
        <label>Cliente *<select id="folderNewClient" required></select></label>
        <label>Tipo lavoro *<select id="folderNewType" required></select></label>
        <label>Codice commessa *<input id="folderNewCode" required maxlength="80"></label>
        <label>Titolo *<input id="folderNewTitle" required maxlength="180"></label>
        <label>Note<textarea id="folderNewNotes"></textarea></label>
        <div class="row wrap"><button type="submit">Crea e seleziona</button><button id="btnFolderJobCreateCancel" type="button" class="secondary">Annulla</button></div>
      </form>
      <div class="table-wrap"><table id="filesTable"></table></div>
    </div>
    <details class="page-help"><summary>Guida Cartella lavori</summary><p>Scansiona la cartella della macchina, seleziona un file e associalo a una commessa esistente. Se la commessa non esiste, usa “Crea nuova commessa”: verrà creata per la macchina e resa subito disponibile per l’associazione.</p></details>
  </section>

  <section id="tab-scheduling" class="tab">
    <div class="page-head"><div><h2>Scheduling</h2><p>Configura la sincronizzazione automatica con le macchine.</p></div><button id="btnSchedulerRun">Esegui sincronizzazione ora</button></div>
    <div class="panel two-col">
      <form id="schedulingForm" class="form compact">
        <label class="check"><input name="enabled" type="checkbox"> Scheduler attivo</label>
        <label>Intervallo sincronizzazione (minuti)<input name="interval_minutes" type="number" min="1" max="1440" required></label>
        <label>Finestra produzione da rileggere (minuti)<input name="production_lookback_minutes" type="number" min="1" max="10080" required></label>
        <label>Finestra allarmi da rileggere (minuti)<input name="alarm_lookback_minutes" type="number" min="1" max="10080" required></label>
        <label>Record per pagina API<input name="page_limit" type="number" min="10" max="500" required></label>
        <label>Aggiornamento dashboard (secondi)<input name="dashboard_refresh_seconds" type="number" min="5" max="120" required></label>
        <button type="submit">Salva scheduling</button>
      </form>
      <div><h3>Ultima esecuzione</h3><div id="schedulerLastRun" class="info-box">Nessuna esecuzione registrata.</div><h3>Installazione cron</h3><p class="muted">Esegui <code>php cli/scheduler.php</code> ogni minuto dal Task Scheduler del NAS. Lo script applica internamente l'intervallo configurato qui e usa un lock per evitare esecuzioni sovrapposte.</p></div>
    </div>
    <details class="page-help"><summary>Guida Scheduling</summary><p>Imposta ogni quanti minuti sincronizzare le macchine e quanta storia rileggere per produzione e allarmi. Il Task Scheduler deve eseguire lo script ogni minuto; l’applicazione applica l’intervallo configurato.</p></details>
  </section>

  <section id="tab-costs" class="tab">
    <div class="page-head"><div><h2>Parametri costi</h2><p>Valori usati nel report economico per commessa.</p></div></div>
    <div class="panel two-col">
      <form id="costForm" class="form compact">
        <label>Costo macchina / ora (€)<input name="machine_hour" type="number" min="0" step="0.01"></label>
        <label>Costo bordo / metro (€)<input name="edge_meter" type="number" min="0" step="0.0001"></label>
        <label>Costo fisso per commessa (€)<input name="fixed_job" type="number" min="0" step="0.01"></label>
        <label>Maggiorazione / costi generali (%)<input name="overhead_percent" type="number" min="0" step="0.01"></label>
        <button type="submit">Salva costi</button>
      </form>
      <div class="info-box"><strong>Formula report</strong><p>Costo macchina = tempo effettivo dei pannelli × tariffa oraria. Costo bordo = consumo bordo registrato × costo/metro. Al subtotale vengono aggiunti costo fisso e maggiorazione percentuale.</p><p class="muted">I tempi anomali con uscita precedente all'ingresso vengono esclusi dal conteggio.</p></div>
    </div>
    <details class="page-help"><summary>Guida Costi</summary><p>Configura le tariffe usate nel report economico. I valori vengono applicati ai tempi macchina e ai consumi di bordo registrati per ciascuna commessa.</p></details>
  </section>


  <section id="tab-company" class="tab">
    <div class="page-head"><div><h2>Dati azienda</h2><p>Intestazione e riferimenti utilizzati nei report e nei documenti stampati.</p></div></div>
    <div class="panel two-col">
      <form id="companyForm" class="form">
        <h3>Intestazione aziendale</h3>
        <label>Ragione sociale<input name="name" maxlength="180"></label>
        <label>Indirizzo<input name="address" maxlength="255"></label>
        <div class="row"><label>CAP<input name="postal_code" maxlength="20"></label><label>Città<input name="city" maxlength="120"></label><label>Provincia<input name="province" maxlength="20"></label></div>
        <label>Paese<input name="country" maxlength="80" value="Italia"></label>
        <div class="row"><label>Partita IVA<input name="vat_number" maxlength="40"></label><label>Codice fiscale<input name="tax_code" maxlength="40"></label></div>
        <div class="row"><label>Telefono<input name="phone" maxlength="80"></label><label>Email<input name="email" type="email" maxlength="180"></label></div>
        <div class="row"><label>PEC<input name="pec" type="email" maxlength="180"></label><label>Codice SDI<input name="sdi" maxlength="20"></label></div>
        <label>Sito web<input name="website" maxlength="180"></label>
        <label>Nota piè di pagina report<textarea name="report_footer"></textarea></label>
        <button type="submit">Salva dati azienda</button>
      </form>
      <div>
        <form id="companyLogoForm" class="form compact" enctype="multipart/form-data">
          <h3>Logo aziendale</h3>
          <div id="companyLogoPreview" class="logo-preview">Nessun logo configurato.</div>
          <label>Logo<input id="companyLogoFile" name="logo" type="file" accept="image/png,image/jpeg,image/webp"></label>
          <button type="submit">Carica logo</button>
          <p class="muted">Formati ammessi: PNG, JPG, WebP. Dimensione massima 2 MB.</p>
        </form>
      </div>
    </div>
    <details class="page-help"><summary>Guida Dati azienda</summary><p>Inserisci i dati che devono comparire nell’intestazione dei report. Puoi caricare anche il logo aziendale; le modifiche vengono applicate ai report successivi.</p></details>
  </section>

  <section id="tab-settings" class="tab">
    <div class="page-head"><div><h2>Impostazioni macchine</h2><p>La versione API e il base path determinano automaticamente gli endpoint usati.</p></div></div>
    <div class="panel">
      <div class="table-wrap"><table id="machinesTable"></table></div>
      <form id="machineForm" class="form compact form-spaced">
        <h3>Modifica macchina</h3><input type="hidden" name="id">
        <label>Nome<input name="name" required></label>
        <label>Versione API<select name="api_version"><option value="">Non applicabile</option><option value="v1">v1</option><option value="v2">v2</option></select></label>
        <label>Host/IP<input name="host" placeholder="192.168.1.150"></label><label>Porta<input name="port" type="number" min="1" max="65535"></label>
        <label>Base path<input name="base_path" placeholder="/api/v1/ oppure /api/v2/"></label><label>Cartella upload<input name="upload_path" placeholder="uploads/misurazioni"></label>
        <label>Note<textarea name="notes"></textarea></label><label class="check"><input name="active" type="checkbox"> Attiva</label><button type="submit">Salva macchina</button>
      </form>
    </div>
    <details class="page-help"><summary>Guida Impostazioni macchine</summary><p>Configura indirizzo, porta, versione API e percorso delle macchine. Modifica questi parametri solo quando cambia la configurazione di rete o il tipo di integrazione.</p></details>
  </section>
</main>

<div id="toast" class="toast"></div>
<script src="assets/app-20260928-1445.js?v=0929-0955"></script>
</body>
</html>
