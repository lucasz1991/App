window.initializeCalendar = async function () {
    let wire;
    const timeout = 5000; // Timeout in Millisekunden (z. B. 5000 ms = 5 Sekunden)
    const startTime = Date.now();
    while (typeof wire === 'undefined' && Date.now() - startTime < timeout) {
        await new Promise(resolve => setTimeout(resolve, 10));
        wire = Livewire.find(document.getElementById('booking').getAttribute('wire:id'));
    }
    if(wire){
        let showStep = wire.get('showStep');
        if(showStep==2 ){
            // Livewire-Daten abrufen
            let period = wire.get('period');
            let startDate = wire.get('startDate');
            let endDate = wire.get('endDate');
            // Kalender-Element überprüfen
            let calendarEl = document.getElementById('calendar');
            if (!calendarEl) {
                console.error("calendarEl nicht gefunden!");
                return;
            }
            // Sicherstellen, dass die Höhe passt
            while (calendarEl.offsetHeight < (window.innerHeight * 0.3)) {
                await new Promise(resolve => setTimeout(resolve, 10));
            }
            // Events für gespeicherte Daten
            let events = [];
            if (startDate && endDate) {
                events.push({
                    title: '🟢',
                    start: startDate,
                    allDay: true,
                });
                events.push({
                    title: '🔴',
                    start: endDate,
                    allDay: true,
                });
                events.push({
                    start: startDate,
                    end: new Date(new Date(endDate).getTime() + 86400000).toISOString().split('T')[0],
                    rendering: 'background',
                    color: 'rgb(208, 247, 216, 0.7)', // Hintergrundfarbe für Zeitraum
                });
            }
            if (period === 7 || period === 14) {
                    events.push({
                        title: '\u{2003}\u{2003}🎁',
                        start: '2025-01-25',
                        allDay: true,
                    });
                    events.push({
                        start: '2025-01-25',
                        allDay: true,
                        rendering: 'background',
                        color: 'rgba(0, 255, 52, 0.15)', // Hintergrundfarbe
                    });
                }
            // Zerstöre vorherige Kalender-Instanz
            if (calendarEl.calendar) {
                    calendarEl.calendar.destroy();
            }
            // FullCalendar-Instanz erstellen
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'de',
                timeZone: 'local',
                headerToolbar: {
                    left: 'title',
                    center: '',
                    right: 'prev,next'
                },
                validRange: {
                    start: new Date(new Date().setDate(new Date().getDate() + 1)).toISOString().split('T')[0],
                    end: new Date(new Date().setMonth(new Date().getMonth() + 12)).toISOString().split('T')[0],
                },
                initialDate: startDate ? new Date(startDate).toISOString().split('T')[0] : new Date().toISOString().split('T')[0],
                events: events,
                firstDay: 1,
                dayCellDidMount: function (info) {
                    let selectedRanges = [];
                    let publicHolidays = wire.get('publicHolidays');
                    // Wenn es ein Sonntag oder Feiertag ist
                    let checkDate = new Date(info.date.getTime() - info.date.getTimezoneOffset() * 60000); // Zeitzone korrigiert
                    // Wochentag überprüfen (0 = Sonntag)
                    let infoisSunday = checkDate.getDay() === 0;

                    // Feiertage überprüfen
                    let infoisHoliday = publicHolidays.includes(checkDate.toISOString().split('T')[0]);
                    // Wenn es ein blockierter Tag ist
                    let blockedDays = wire.get('blockedDates');
                    let isBlocked = blockedDays.includes(checkDate.toISOString().split('T')[0]);
                    if (infoisSunday || infoisHoliday) {
                        info.el.classList.add('disabled-day');
                        info.el.classList.add('cursor-not-allowed');
                        info.el.style.pointerEvents = 'none';
                        info.el.style.backgroundColor = '#f0f0f0';
                    }
                    if (isBlocked ) {
                        // Wenn das Datum blockiert ist
                        info.el.classList.add('disabled-day'); 
                        info.el.classList.add('cursor-not-allowed'); 
                        info.el.classList.add('disabledday'); 
                        info.el.style.pointerEvents = 'none'; // Deaktiviert Interaktionen
                        info.el.style.backgroundColor = '#ffdddd'; // Weiches Rot für blockierte Tage
                    };
                    const handleDaySelection = function () {
                        if (info.el.classList.contains('fc-day-disabled') || info.el.classList.contains('disabled-day')) {
                            // Abbrechen, wenn der Tag deaktiviert ist
                            return;
                        }
                        // Start- und Enddatum bestimmen
                        let infoDate = new Date(info.date);
                        let startDate = new Date(infoDate.getTime() - infoDate.getTimezoneOffset() * 60000);
                        let endDate = new Date(startDate);
                        
                        let daysHighlighted = 0;
                        while (daysHighlighted < period) {
                            let formattedDate = endDate.toISOString().split('T')[0];
                            let isSunday = endDate.getDay() === 0;
                            let isHoliday = publicHolidays.includes(formattedDate);
                            if (!isSunday && !isHoliday) {
                                daysHighlighted++;
                            }
                            // Zum nächsten Tag wechseln
                            endDate.setDate(endDate.getDate() + 1);
                        }
                        // Zurück zum letzten gültigen Tag
                        endDate.setDate(endDate.getDate() - 1);
                            // Stelle sicher, dass der letzte Tag gültig ist
                            let formattedDate = endDate.toISOString().split('T')[0];
                            let isSunday = endDate.getDay() === 0;
                            let isHoliday = publicHolidays.includes(formattedDate);
                        
                            if (isSunday || isHoliday) {
                                let validDateFound = false;
                                while (!validDateFound) {
                                    endDate.setDate(endDate.getDate() + 1); // Suche nach dem nächsten gültigen Tag
                                    formattedDate = endDate.toISOString().split('T')[0];
                                    isSunday = endDate.getDay() === 0;
                                    isHoliday = publicHolidays.includes(formattedDate);
                                    if (!isSunday && !isHoliday) {
                                        validDateFound = true;
                                    }
                                }
                            }
                        // Bestehende Events entfernen
                        let existingEvents = calendar.getEvents();
                        existingEvents.forEach(event => event.remove());
                        // Einzug-Event hinzufügen
                        calendar.addEvent({
                            title: '🟢',
                            start: startDate.toISOString().split('T')[0],
                            allDay: true,
                        });
                        // Auszug-Event hinzufügen
                        calendar.addEvent({
                            title: '🔴',
                            start: endDate.toISOString().split('T')[0],
                            allDay: true,
                        }); 
                        if (period === 7 || period === 14) {
                            calendar.addEvent({
                                title: '\u{2003}\u{2003}🎁',
                                start: '2025-01-25',
                                allDay: true,
                            });
                            calendar.addEvent({
                                start: '2025-01-25',
                                allDay: true,
                                rendering: 'background',
                                color: 'rgba(0, 255, 52, 0.15)', // Hintergrundfarbe
                            });
                        }
                        // Zeitrahmen-Ereignis hinzufügen (optionaler Hintergrund)
                        endDate.setDate(endDate.getDate() + 1);
                        calendar.addEvent({
                            start: startDate.toISOString().split('T')[0],
                            end: endDate.toISOString().split('T')[0],
                            rendering: 'background',
                            color: 'rgba(0, 255, 52, 0.15)', // Hintergrundfarbe
                        });
                        endDate.setDate(endDate.getDate() - 1);
                        let alpineData = Alpine.$data(document.querySelector('#alpine-calenderdata')); // Alpine Daten abrufen
                        if (!alpineData) {
                            console.error("alpine-calenderdata nicht gefunden!");
                            return;
                        }
                        alpineData.XstartDate = startDate.toISOString().split('T')[0];
                        alpineData.XendDate = endDate.toISOString().split('T')[0];
                        alpineData.XisDateSelected = true;
                        let calendarButton = document.getElementById('calender-confirm-button');
                        calendarButton.classList.remove('hidden');
                    };
                    // Klick-Handler hinzufügen
                    info.el.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        handleDaySelection();
                    });
                    // Doppelklick-Handler verhindern
                    info.el.addEventListener('dblclick', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        handleDaySelection();
                    });
                    // Hover-Handler hinzufügen
                    info.el.addEventListener('mouseenter', function () {
                        let startDate = new Date(info.date.getTime() - info.date.getTimezoneOffset() * 60000); // Korrigiert die Zeitzone
                        let currentDate = new Date(startDate); // Startdatum für die Periode
                        let daysHighlighted = 0;
                        // Entferne bestehende Markierungen (Klassen)
                        document.querySelectorAll('.hover-highlight').forEach(el => {
                            el.classList.remove('hover-highlight');
                        });
                        // Markiere nur Tage, die sichtbar sind
                        while (daysHighlighted < period) {
                            let formattedDate = currentDate.toISOString().split('T')[0];
                            let dayCell = document.querySelector(`[data-date="${formattedDate}"]`);
                            // Prüfen, ob der Tag sichtbar ist
                            // Prüfen, ob der Tag sichtbar ist (gerendert im DOM)
                            if(dayCell != null){
                                const rect = dayCell.getBoundingClientRect();
                                    if (rect.width > 0 && rect.height > 0) {
                                        // Überspringe deaktivierte Tage
                                        let isSunday = currentDate.getDay() === 0;
                                        let isHoliday = publicHolidays.includes(formattedDate);
                                        if (!isSunday && !isHoliday) {
                                            // Klasse hinzufügen, um den Tag zu markieren
                                            dayCell.classList.add('hover-highlight');
                                            daysHighlighted++;
                                        }
                                    }
                                // Zum nächsten Tag wechseln
                            }else{
                                daysHighlighted++;
                            }
                            currentDate.setDate(currentDate.getDate() + 1);
                        }
                    });
                    // Entferne Hover-Highlight bei Verlassen
                    info.el.addEventListener('mouseleave', function () {
                        // Entferne die Klasse von allen markierten Eltern-Elementen
                        document.querySelectorAll('.hover-highlight').forEach(el => {
                            el.classList.remove('hover-highlight');
                        });
                    });
                }
            });
            // Speichere die Instanz im DOM-Element
            calendarEl.calendar = calendar;
            calendar.render();
            console.log('initializeCalendar');
        }
    }else{
        console.log("Livewire-Komponente = booking nicht gefunden!");
        return;
    }
};
