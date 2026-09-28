# JD Industry - Gestionale Commesse

Applicazione interna PHP 8 + MariaDB per gestione commesse, integrazione Maestro Active e raccolta file via SMB.

## Stato produzione

Questa versione porta il prototipo iniziale verso un utilizzo operativo:

- dashboard orientata all'operatore con stato macchine;
- polling live leggero dello stato macchina dalla dashboard;
- sincronizzazione automatica schedulata di stato, produzione e allarmi;
- riallineamento automatico dello stato commesse quando la macchina segnala commessa corrente/chiusa;
- apertura e chiusura multipla di commesse verso Maestro Active;
- configurazione costi e report economico per commessa;
- pagina Scheduling per configurare frequenza, lookback e paginazione API;
- supporto API Maestro Active v1/v2 determinato dalla configurazione della tabella `machines`.

## Configurazione attuale bordatrice

La configurazione viene letta dal database. Nel dump di riferimento la bordatrice usa Maestro Active **v1** con host `192.168.1.150`, porta `81` e base path `/api/v1/`.

Per v1 vengono usati gli endpoint documentati:

- `machine/info`
- `status/Machine`
- `status/alarms`
- `report/alarms`
- `report/production`
- `order/open`
- `order`
- `order/close`

Per una macchina configurata come v2 il client passa automaticamente agli endpoint v2 corrispondenti.

## Installazione / aggiornamento

Requisiti:

- PHP 8.x con `pdo_mysql` e preferibilmente `curl`;
- MariaDB 10.x;
- Web Station o web server equivalente.

Per una nuova installazione importa:

```text
database/install.sql
```

Per aggiornare un database esistente importa:

```text
database/migrations/20260928_production.sql
```

Il file con credenziali locali non viene versionato. Copia:

```text
app/config.local.example.php -> app/config.local.php
```

e imposta utente/password MariaDB. In alternativa puoi usare le variabili ambiente `COMMESSE_DB_HOST`, `COMMESSE_DB_PORT`, `COMMESSE_DB_NAME`, `COMMESSE_DB_USER`, `COMMESSE_DB_PASS`.

## Scheduler

La pagina **Scheduling** definisce l'intervallo applicativo. Sul NAS configura il Task Scheduler per eseguire **ogni minuto**:

```bash
php /percorso/commesse-lite/cli/scheduler.php
```

Lo script:

1. controlla se lo scheduler è abilitato;
2. verifica se è trascorso l'intervallo configurato;
3. usa un lock MariaDB per impedire esecuzioni sovrapposte;
4. interroga ogni macchina `maestro_rest` attiva;
5. aggiorna stato e allarmi attivi;
6. importa report produzione e storico allarmi con paginazione;
7. associa automaticamente i record di produzione alle commesse quando il codice coincide;
8. porta una commessa a `in_lavorazione` quando risulta corrente/Running e a `chiusa` quando la macchina la segnala chiusa.

La dashboard effettua inoltre un polling leggero dello **stato** macchina con la frequenza in secondi configurata nella stessa pagina; l'importazione pesante resta demandata allo scheduler.

## Limite delle API Maestro Active

La specifica fornita non espone un endpoint per ottenere l'elenco completo delle commesse aperte presenti in macchina. Il gestionale può quindi sincronizzare con certezza:

- commessa corrente;
- stato corrente;
- ultima commessa chiusa;
- produzione storica con il relativo nome commessa;
- allarmi attivi e storico allarmi.

Le commesse remote che compaiono nei report ma non esistono nel gestionale vengono registrate in `production_records` con `job_id = NULL`, senza creare automaticamente anagrafiche incomplete.

## Costi e report

I parametri globali configurabili sono:

- costo macchina / ora;
- costo bordo / metro;
- costo fisso per commessa;
- maggiorazione percentuale per costi generali.

Il report commessa calcola il tempo come somma dei tempi effettivi dei pannelli (`datetime_start` -> `datetime_end`) e il consumo bordo convertendo i valori registrati da mm a metri. I record temporalmente anomali con uscita precedente all'ingresso non vengono conteggiati nel tempo macchina.

## Sicurezza repository

`app/config.local.php` e i file caricati in `public/uploads/misurazioni/` sono esclusi da Git. Il repository contiene solo il `.gitkeep` della cartella upload.
