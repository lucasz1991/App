// Datenmodell, Rechenregeln und Prüfungen der Wagenliste.
//
// Diese Datei bildet die Excel-Wagenliste v16_5 nach (Blätter Wagenliste,
// Bremszettel, Meldezettel): gleiche Felder, gleiche Summen, gleiche
// Bremsabzüge. Sie ist bewusst frei von DOM- und Alpine-Bezügen, damit die
// Regeln ohne Browser prüfbar bleiben. Die Excel-Vorlage rechnet beim Export
// selbst nach; maßgeblich bleibt die Datei.

export const MAX_WAGONS = 40;
export const BRAKE_SOLE_TYPES = ['K', 'L', 'LL', 'D', 'F'];
export const COMPOSITE_SOLE_TYPES = ['K', 'L', 'LL'];
export const MAX_SPEED_OPTIONS = ['80', '90', '100', '120'];
export const BRAKE_POSITIONS = ['R/P', 'G'];
export const LIST_ORDERS = ['head', 'tail'];
export const NINETY_PERCENT_RULES = ['no', 'yes', 'yes800'];
export const ROUTE_CLASSES = ['A', 'B1', 'B2', 'C2', 'C3', 'C4', 'CE', 'D2', 'D3', 'D4', 'E4', 'E5'];
export const PAPER_OPTIONS = ['electronic', 'paper', 'both', 'none'];
export const TRANSPORT_ORDER_OPTIONS = ['complete', 'standing', 'none'];
export const REPORT_TASKS = [
    'coupling',
    'wagonInspection',
    'dangerousGoodsCheck',
    'fullBrakeTest',
    'tailSignal',
    'securingRemoved',
    'simpleBrakeTest',
];
export const EXTRA_WORK_ROWS = 5;

// Radsatzlast (t) und Meterlast (t/m) je Streckenklasse nach EN 15528,
// wie im Blatt ERA_Codes der Vorlage.
export const ROUTE_CLASS_LIMITS = {
    A: { axleLoad: 16, meterLoad: 5 },
    B1: { axleLoad: 18, meterLoad: 5 },
    B2: { axleLoad: 18, meterLoad: 6.4 },
    C2: { axleLoad: 20, meterLoad: 6.4 },
    C3: { axleLoad: 20, meterLoad: 7.2 },
    C4: { axleLoad: 20, meterLoad: 8 },
    CE: { axleLoad: 20, meterLoad: 8 },
    D2: { axleLoad: 22.5, meterLoad: 6.4 },
    D3: { axleLoad: 22.5, meterLoad: 7.2 },
    D4: { axleLoad: 22.5, meterLoad: 8 },
    E4: { axleLoad: 25, meterLoad: 8 },
    E5: { axleLoad: 25, meterLoad: 8.8 },
};

// Einstellungen der Vorlage (Blatt ERA_Codes, Spalte X).
export const LIST_PARAMETERS = {
    maxTrainLength: 740,
    lengthTolerance: 1,
    lengthToleranceFrom: '2026-12-13',
    maxAxles: 250,
    maxCalculationLength: 815,
    gLengthAdditive: true,
};

/** Heutiges Datum in Ortszeit als JJJJ-MM-TT (toISOString wäre UTC und kippt nachts auf den Vortag). */
export const localToday = () => {
    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
};

export const emptyMeta = () => ({
    trainNumber: '',
    date: localToday(),
    origin: '',
    destination: '',
    reference: '',
    customer: '',
    track: '',
    preparedBy: '',
    preparedTime: '',
});

export const emptyWagon = () => ({
    number12: '',
    number34: '',
    number58: '',
    number911: '',
    checkDigit: '',
    category: '',
    categoryAuto: '',
    axles: '',
    length: '',
    wagonWeight: '',
    loadWeight: '',
    brakeG: '',
    brakeP: '',
    shippingStation: '',
    destinationStation: '',
    brakeType: '',
    parkingBrake: '',
    maxSpeed: '',
    unNumber: '',
    hazardLabel: '',
    remark: '',
});

export const emptyBrakeSheet = () => ({
    tractionWeight: '',
    tractionBrakeWeight: '',
    tractionAxles: '',
    tractionLength: '',
    minimumBrakePercentage: '',
    brakePosition: '',
    listOrder: 'head',
    ninetyPercentRule: 'no',
    timetableLoad: '',
    timetableLength: '',
    timetableSpeed: '',
    routeClass: '',
    singleReleaseBrakes: '',
    matrossowBrakes: '',
    nbuepBrake: '',
    emergencyBrakeBridge: '',
    passengerFeatureHzee: '',
    passengerFeatureNOe: '',
    passengerFeatureTb0: '',
    passengerFeatureOZub: '',
    passengerFeatureOther: '',
    dangerousGoods: '',
    epBrake: '',
    issuerName: '',
});

export const emptyReport = () => ({
    papers: '',
    transportOrder: '',
    uicCheck: '',
    tasks: Object.fromEntries(REPORT_TASKS.map((task) => [task, false])),
    wagonInspectionLevel: '',
    extraWork: Array.from({ length: EXTRA_WORK_ROWS }, () => ''),
    remarks: '',
});

export const numeric = (value) => {
    const normalized = String(value ?? '').trim().replace(',', '.');
    const parsed = Number.parseFloat(normalized);

    return Number.isFinite(parsed) ? parsed : 0;
};

export const hasValue = (value) => String(value ?? '').trim() !== '';

const pickKnown = (template, source) => {
    const result = { ...template };

    for (const key of Object.keys(template)) {
        if (source && Object.prototype.hasOwnProperty.call(source, key) && source[key] !== undefined && source[key] !== null) {
            result[key] = source[key];
        }
    }

    return result;
};

/**
 * Einen gespeicherten Wagen auf das aktuelle Modell bringen. Entwürfe aus
 * der früheren Liste hatten zwei Achsspalten (leer/beladen) und die
 * Scheibenbremse als eigenes Kästchen; die v16_5 führt eine Achsspalte und
 * die Bremssohlenart K/L/LL/D/F.
 */
export const normalizeWagon = (wagon = {}) => {
    const source = wagon && typeof wagon === 'object' ? wagon : {};
    const normalized = pickKnown(emptyWagon(), source);

    if (!hasValue(normalized.axles) && (hasValue(source.axlesEmpty) || hasValue(source.axlesLoaded))) {
        const axles = numeric(source.axlesEmpty) + numeric(source.axlesLoaded);
        normalized.axles = axles > 0 ? String(axles) : '';
    }

    const sole = String(normalized.brakeType ?? '').trim().toUpperCase();
    normalized.brakeType = BRAKE_SOLE_TYPES.includes(sole) ? sole : '';
    if (source.discBrake === true) {
        normalized.brakeType = 'D';
    }

    return normalized;
};

export const normalizeBrakeSheet = (brakeSheet = {}) => {
    const normalized = pickKnown(emptyBrakeSheet(), brakeSheet);

    if (!BRAKE_POSITIONS.includes(normalized.brakePosition)) normalized.brakePosition = '';
    if (!LIST_ORDERS.includes(normalized.listOrder)) normalized.listOrder = 'head';
    if (!NINETY_PERCENT_RULES.includes(normalized.ninetyPercentRule)) normalized.ninetyPercentRule = 'no';
    if (!ROUTE_CLASSES.includes(normalized.routeClass)) normalized.routeClass = '';

    return normalized;
};

export const normalizeReport = (report = {}) => {
    const source = report && typeof report === 'object' ? report : {};
    const normalized = emptyReport();

    normalized.papers = PAPER_OPTIONS.includes(source.papers) ? source.papers : '';
    normalized.transportOrder = TRANSPORT_ORDER_OPTIONS.includes(source.transportOrder) ? source.transportOrder : '';
    normalized.uicCheck = ['yes', 'no'].includes(source.uicCheck) ? source.uicCheck : '';
    normalized.wagonInspectionLevel = ['1', '2', '3', '4'].includes(String(source.wagonInspectionLevel ?? ''))
        ? String(source.wagonInspectionLevel)
        : '';
    normalized.remarks = typeof source.remarks === 'string' ? source.remarks : '';

    for (const task of REPORT_TASKS) {
        normalized.tasks[task] = source.tasks?.[task] === true;
    }

    normalized.extraWork = Array.from({ length: EXTRA_WORK_ROWS }, (_, index) => {
        const entry = source.extraWork?.[index];

        return typeof entry === 'string' ? entry : '';
    });

    return normalized;
};

// ---- Wagennummer ---------------------------------------------------------

export const wagonDigits = (wagon) =>
    `${wagon?.number12 ?? ''}${wagon?.number34 ?? ''}${wagon?.number58 ?? ''}${wagon?.number911 ?? ''}`.replace(/\D/g, '');

export const hasWagonNumber = (wagon) =>
    [wagon?.number12, wagon?.number34, wagon?.number58, wagon?.number911, wagon?.checkDigit].some(hasValue);

export const wagonNumber = (wagon) =>
    [wagon?.number12, wagon?.number34, wagon?.number58, wagon?.number911]
        .filter(Boolean)
        .join(' ') + (wagon?.checkDigit ? `-${wagon.checkDigit}` : '');

/** Prüfziffer nach dem Luhn-Verfahren der EVN (Beschluss (EU) 2018/1614). */
export const expectedCheckDigit = (wagon) => {
    const digits = wagonDigits(wagon);
    if (digits.length !== 11) return null;

    const sum = digits.split('').reduce((total, digit, index) => {
        const product = Number(digit) * (index % 2 === 0 ? 2 : 1);

        return total + Math.floor(product / 10) + (product % 10);
    }, 0);

    return (10 - (sum % 10)) % 10;
};

export const checkState = (wagon) => {
    const expected = expectedCheckDigit(wagon);
    if (expected === null || String(wagon?.checkDigit ?? '').length !== 1) return 'incomplete';

    return Number(wagon.checkDigit) === expected ? 'valid' : 'invalid';
};

/** Zwölf Ziffern (mit Leerzeichen oder Bindestrich) in die fünf Felder teilen. */
export const splitWagonNumber = (value) => {
    const source = String(value ?? '').trim();
    if (source === '' || !/^[0-9\s-]+$/.test(source)) return null;

    const digits = source.replace(/\D/g, '');
    if (digits.length !== 12) return null;

    return {
        number12: digits.slice(0, 2),
        number34: digits.slice(2, 4),
        number58: digits.slice(4, 8),
        number911: digits.slice(8, 11),
        checkDigit: digits.slice(11, 12),
    };
};

export const isWagonFilled = (wagon) => Boolean(
    wagonDigits(wagon)
    || wagon?.checkDigit
    || wagon?.category
    || wagon?.axles
    || wagon?.length
    || wagon?.wagonWeight
    || wagon?.loadWeight
    || hasValue(wagon?.brakeG)
    || hasValue(wagon?.brakeP)
    || wagon?.shippingStation
    || wagon?.destinationStation
    || wagon?.brakeType
    || wagon?.parkingBrake
    || wagon?.maxSpeed
    || wagon?.unNumber
    || wagon?.hazardLabel
    || wagon?.remark,
);

export const wagonTotalWeight = (wagon) => {
    if (!hasValue(wagon?.wagonWeight) && !hasValue(wagon?.loadWeight)) return 0;

    return numeric(wagon.wagonWeight) + numeric(wagon.loadWeight);
};

export const wagonBrakeWeight = (wagon) => numeric(wagon?.brakeG) + numeric(wagon?.brakeP);

/** Pflichtfelder der Liste: Nummer, Gattung, Achsen, LüP, Gewicht Wagen und ein Bremsgewicht (0 = Bremse aus). */
export const missingWagonFields = (wagon) => {
    const missing = [];

    if (![wagon?.number12, wagon?.number34, wagon?.number58, wagon?.number911, wagon?.checkDigit].every(hasValue)) {
        missing.push('number');
    }
    if (!hasValue(wagon?.category)) missing.push('category');
    if (!hasValue(wagon?.axles)) missing.push('axles');
    if (!hasValue(wagon?.length)) missing.push('length');
    if (!hasValue(wagon?.wagonWeight)) missing.push('wagonWeight');
    if (!hasValue(wagon?.brakeG) && !hasValue(wagon?.brakeP)) missing.push('brakeWeight');

    return missing;
};

/** Ampel eines Wagens: leer, fehlerhaft, unvollständig oder vollständig. */
export const wagonStatus = (wagon) => {
    if (!isWagonFilled(wagon)) return 'empty';
    if (checkState(wagon) === 'invalid') return 'invalid';

    return checkState(wagon) === 'valid' && missingWagonFields(wagon).length === 0 ? 'complete' : 'partial';
};

// ---- Summen und Bremsrechnung -------------------------------------------

const roundUp = (value) => Math.ceil(value - 1e-9);
const roundDown = (value) => Math.floor(value + 1e-9);

/**
 * Summen der Wagenliste und Bremsabzüge wie in der Vorlage (Zeilen 47 bis 53):
 * R/P bis 500 m 0 %, über 500/600/700 m 5/10/19 % auf P; G zunächst 25 %,
 * über 700 m weitere 5 %. Zugstellung G: keine Abzüge außer 5 % über 700 m.
 * Gerechnet wird nur im gültigen Bereich (Länge bis 815 m, je Wagen G oder P).
 */
export const computeTotals = (wagons, brakeSheet = {}) => {
    const active = wagons.filter(isWagonFilled);
    const sum = (field) => active.reduce((total, wagon) => total + numeric(wagon[field]), 0);
    const braked = (wagon) => wagonBrakeWeight(wagon) > 0;

    const length = sum('length');
    const axles = sum('axles');
    const brakeG = sum('brakeG');
    const brakeP = sum('brakeP');
    const totalWeight = active.reduce((total, wagon) => total + wagonTotalWeight(wagon), 0);
    const position = BRAKE_POSITIONS.includes(brakeSheet.brakePosition) ? brakeSheet.brakePosition : '';
    const bothBrakeWeights = active.filter((wagon) => numeric(wagon.brakeG) > 0 && numeric(wagon.brakeP) > 0).length;

    const calculationValid = length > 0
        && length <= LIST_PARAMETERS.maxCalculationLength
        && bothBrakeWeights === 0
        && (position !== 'G' || brakeP === 0);

    const pRate = calculationValid && position !== 'G'
        ? (length > 700 ? 0.19 : (length > 600 ? 0.10 : (length > 500 ? 0.05 : 0)))
        : 0;
    const gBaseRate = calculationValid && position !== 'G' ? 0.25 : 0;
    const gLengthRate = calculationValid && length > 700 ? 0.05 : 0;

    const deductionG = brakeG * gBaseRate;
    const deductionP = brakeP * pRate;
    const deductionGLength = (LIST_PARAMETERS.gLengthAdditive ? brakeG : brakeG - deductionG) * gLengthRate;
    const usableBrakeWeight = calculationValid
        ? Math.max(0, roundDown(brakeG - deductionG - deductionGLength + brakeP - deductionP))
        : null;

    const speeds = active.map((wagon) => numeric(wagon.maxSpeed)).filter((speed) => speed > 0);
    const dangerousGoods = active.filter((wagon) => hasValue(wagon.unNumber) || hasValue(wagon.hazardLabel));

    return {
        wagons: active.length,
        axles,
        length,
        lengthRounded: length > 0 ? roundUp(length) : 0,
        wagonWeight: sum('wagonWeight'),
        loadWeight: sum('loadWeight'),
        totalWeight,
        weightRounded: totalWeight > 0 ? roundUp(totalWeight) : 0,
        brakeG,
        brakeP,
        brakePosition: position,
        calculationValid,
        bothBrakeWeights,
        pRate,
        gBaseRate,
        gLengthRate,
        deductionG,
        deductionP,
        deductionP19: pRate === 0.19 ? deductionP : 0,
        deductionP10: pRate === 0.10 ? deductionP : 0,
        deductionP5: pRate === 0.05 ? deductionP : 0,
        deductionGLength,
        usableBrakeWeight,
        brakeCount: active.filter(braked).length,
        compositeBrakes: active.filter((wagon) => COMPOSITE_SOLE_TYPES.includes(wagon.brakeType) && braked(wagon)).length,
        discBrakes: active.filter((wagon) => wagon.brakeType === 'D' && braked(wagon)).length,
        castIronWagons: active.filter((wagon) => wagon.brakeType === 'F').length,
        brakedAxles: active.filter(braked).reduce((total, wagon) => total + numeric(wagon.axles), 0),
        requiredBrakedAxles: axles > 0 ? roundUp((axles * 9) / 10) : 0,
        minWagonSpeed: speeds.length ? Math.min(...speeds) : 0,
        dangerousGoodsWagons: dangerousGoods.length,
    };
};

/**
 * Erstes und letztes Fahrzeug des Wagenzuges. Zählen dürfen nur Zeilen mit
 * Wagennummer; die Reihenfolge der Liste (ab Zugspitze oder ab Zugschluss)
 * bestimmt, welches Ende vorne ist.
 */
export const trainEnds = (wagons, brakeSheet = {}) => {
    const numbered = wagons
        .map((wagon, index) => ({ wagon, index }))
        .filter(({ wagon }) => hasWagonNumber(wagon));
    if (!numbered.length) return { first: null, last: null, beforeLast: null, positions: [] };

    const ordered = brakeSheet.listOrder === 'tail' ? [...numbered].reverse() : numbered;

    return {
        first: ordered[0],
        last: ordered[ordered.length - 1],
        beforeLast: ordered.length > 1 ? ordered[ordered.length - 2] : null,
        positions: ordered,
    };
};

/** Bremszettel: Wagenzug, arbeitende Triebfahrzeuge und Gesamtzug. */
export const computeBrakeSheet = (totals, brakeSheet = {}, ends = null) => {
    const tractionWeight = numeric(brakeSheet.tractionWeight);
    const tractionBrakeWeight = numeric(brakeSheet.tractionBrakeWeight);
    const tractionKnown = hasValue(brakeSheet.tractionWeight)
        && tractionWeight > 0
        && hasValue(brakeSheet.tractionBrakeWeight);
    const trainWeight = totals.weightRounded + tractionWeight;
    const brakeWeight = totals.usableBrakeWeight === null ? null : totals.usableBrakeWeight + tractionBrakeWeight;
    const minimum = numeric(brakeSheet.minimumBrakePercentage);

    const availablePercentage = tractionKnown && trainWeight > 0 && brakeWeight !== null
        ? roundDown((brakeWeight * 100) / trainWeight)
        : null;

    const ninetyRule = NINETY_PERCENT_RULES.includes(brakeSheet.ninetyPercentRule) ? brakeSheet.ninetyPercentRule : 'no';
    const ninetyApplies = ninetyRule === 'yes' || (ninetyRule === 'yes800' && totals.weightRounded > 0 && totals.weightRounded <= 800);
    const ninetySatisfied = ninetyApplies && totals.axles > 0 && totals.brakedAxles >= totals.requiredBrakedAxles;

    const missingPercentage = minimum > 0 && availablePercentage !== null && !ninetySatisfied
        ? Math.max(0, minimum - availablePercentage)
        : null;

    const timetableSpeed = numeric(brakeSheet.timetableSpeed);
    const slowerVehicles = timetableSpeed > 0 && totals.minWagonSpeed > 0
        ? totals.minWagonSpeed < timetableSpeed
        : null;

    return {
        trainWeight,
        brakeWeight,
        axles: totals.axles + numeric(brakeSheet.tractionAxles),
        trainLength: totals.lengthRounded + numeric(brakeSheet.tractionLength),
        tractionKnown,
        minimumPercentage: minimum,
        availablePercentage,
        missingPercentage,
        ninetyRule,
        ninetySatisfied,
        multiReleaseBrakes: Math.max(0, totals.brakeCount - numeric(brakeSheet.singleReleaseBrakes)),
        slowerVehicles,
        lowestSpeed: slowerVehicles ? totals.minWagonSpeed : 0,
        dangerousGoods: brakeSheet.dangerousGoods === 'yes' || brakeSheet.dangerousGoods === 'no'
            ? brakeSheet.dangerousGoods
            : (totals.wagons === 0 ? '' : (totals.dangerousGoodsWagons > 0 ? 'yes' : 'no')),
        firstVehicle: ends?.first ? wagonNumber(ends.first.wagon) : '',
        lastVehicle: ends?.last ? wagonNumber(ends.last.wagon) : '',
    };
};

const permittedTrainLength = (brakeSheet, date) => {
    const timetable = numeric(brakeSheet.timetableLength);
    const base = timetable > 0 ? timetable : LIST_PARAMETERS.maxTrainLength;
    const tolerance = typeof date === 'string' && date >= LIST_PARAMETERS.lengthToleranceFrom
        ? LIST_PARAMETERS.lengthTolerance
        : 0;

    return base + tolerance;
};

/**
 * Prüfhinweise zur Liste, wichtigste zuerst. Geliefert werden Schlüssel und
 * Werte; die Texte stehen in den Sprachdateien (app.wagon_checks).
 * tone: error = so nicht abfahrbereit, warning = prüfen, info = Hinweis.
 */
export const computeChecks = (wagons, meta = {}, brakeSheet = {}) => {
    const totals = computeTotals(wagons, brakeSheet);
    const ends = trainEnds(wagons, brakeSheet);
    const sheet = computeBrakeSheet(totals, brakeSheet, ends);
    const checks = [];
    const add = (id, tone, values = {}) => checks.push({ id, tone, values });
    const positions = (list) => list.map((index) => index + 1).join(', ');

    if (totals.wagons === 0) return checks;

    const filled = wagons.map((wagon, index) => ({ wagon, index })).filter(({ wagon }) => isWagonFilled(wagon));

    const invalidNumbers = filled.filter(({ wagon }) => checkState(wagon) === 'invalid').map(({ index }) => index);
    if (invalidNumbers.length) add('numberInvalid', 'error', { positions: positions(invalidNumbers) });

    const seen = new Map();
    for (const { wagon, index } of filled) {
        const digits = `${wagonDigits(wagon)}${wagon.checkDigit ?? ''}`;
        if (digits.length !== 12) continue;
        seen.set(digits, [...(seen.get(digits) || []), index]);
    }
    const duplicates = [...seen.values()].filter((list) => list.length > 1).flat();
    if (duplicates.length) add('numberDuplicate', 'error', { positions: positions(duplicates) });

    if (totals.bothBrakeWeights > 0) {
        add('bothBrakeWeights', 'error', {
            positions: positions(filled
                .filter(({ wagon }) => numeric(wagon.brakeG) > 0 && numeric(wagon.brakeP) > 0)
                .map(({ index }) => index)),
        });
    }

    if (totals.length <= 0) {
        add('lengthMissing', 'error');
    } else if (totals.length > LIST_PARAMETERS.maxCalculationLength) {
        add('lengthOverCalculation', 'error', { limit: LIST_PARAMETERS.maxCalculationLength });
    }

    if (totals.brakePosition === 'G' && totals.brakeP > 0) add('positionGWithP', 'warning');

    if (ends.last && wagonBrakeWeight(ends.last.wagon) === 0) {
        add(ends.beforeLast && wagonBrakeWeight(ends.beforeLast.wagon) === 0 ? 'lastTwoWithoutBrake' : 'lastWithoutBrake', 'error');
    }
    if (ends.first && ends.first !== ends.last && wagonBrakeWeight(ends.first.wagon) === 0) add('firstWithoutBrake', 'warning');

    if (sheet.missingPercentage !== null && sheet.missingPercentage > 0) {
        add('brakePercentageMissing', 'error', {
            missing: sheet.missingPercentage,
            available: sheet.availablePercentage,
            minimum: sheet.minimumPercentage,
        });
    }

    const incomplete = filled.filter(({ wagon }) => checkState(wagon) !== 'invalid' && missingWagonFields(wagon).length > 0)
        .map(({ index }) => index);
    if (incomplete.length) add('requiredMissing', 'warning', { positions: positions(incomplete) });

    const permitted = permittedTrainLength(brakeSheet, meta.date);
    if (sheet.trainLength > permitted) add('trainTooLong', 'warning', { length: sheet.trainLength, permitted });

    if (sheet.axles > LIST_PARAMETERS.maxAxles) add('tooManyAxles', 'warning', { axles: sheet.axles, limit: LIST_PARAMETERS.maxAxles });

    const timetableLoad = numeric(brakeSheet.timetableLoad);
    if (timetableLoad > 0 && totals.weightRounded > timetableLoad) {
        add('loadOverTimetable', 'warning', { weight: totals.weightRounded, load: timetableLoad });
    }

    if (totals.brakePosition !== 'G' && totals.weightRounded > 1200) {
        const leadingInP = ends.positions.slice(0, 5).filter(({ wagon }) => numeric(wagon.brakeP) > 0).length;
        if (leadingInP > 0) add('over1200InP', 'warning', { count: leadingInP });
    }
    if (totals.brakePosition !== 'G' && totals.weightRounded > 1600) {
        add('over1600InP', 'warning');
    } else if (totals.brakePosition !== 'G' && totals.weightRounded > 800) {
        add('over800', 'info');
    }

    if (sheet.slowerVehicles) add('slowerVehicles', 'warning', { lowest: totals.minWagonSpeed, timetable: numeric(brakeSheet.timetableSpeed) });

    const limits = ROUTE_CLASS_LIMITS[brakeSheet.routeClass];
    if (limits) {
        const overloaded = filled.filter(({ wagon }) => {
            const weight = wagonTotalWeight(wagon);
            const axles = numeric(wagon.axles);
            const length = numeric(wagon.length);

            return weight > 0 && axles > 0 && length > 0
                && (weight / axles > limits.axleLoad + 0.0001 || weight / length > limits.meterLoad + 0.0001);
        }).map(({ index }) => index);
        if (overloaded.length) add('routeClassExceeded', 'warning', { positions: positions(overloaded), routeClass: brakeSheet.routeClass });
    }

    const implausible = filled.filter(({ wagon }) => {
        const weight = wagonTotalWeight(wagon);

        return weight > 0 && wagonBrakeWeight(wagon) > 1.3 * weight;
    }).map(({ index }) => index);
    if (implausible.length) add('brakeWeightImplausible', 'warning', { positions: positions(implausible) });

    if (totals.castIronWagons > 0) add('castIronWagons', 'warning', { count: totals.castIronWagons });

    if (!sheet.tractionKnown && !sheet.ninetySatisfied) add('tractionMissing', 'info');

    if (sheet.ninetyRule !== 'no') {
        if (sheet.ninetyRule === 'yes800' && totals.weightRounded > 800) {
            add('ninetyOver800', 'warning');
        } else if (totals.axles > 0) {
            add(sheet.ninetySatisfied ? 'ninetySatisfied' : 'ninetyNotSatisfied', sheet.ninetySatisfied ? 'info' : 'warning', {
                braked: totals.brakedAxles,
                required: totals.requiredBrakedAxles,
            });
        }
    }

    if (totals.dangerousGoodsWagons > 0) {
        add('dangerousGoods', 'info', {
            positions: positions(filled
                .filter(({ wagon }) => hasValue(wagon.unNumber) || hasValue(wagon.hazardLabel))
                .map(({ index }) => index)),
        });
    }

    if (!hasValue(meta.date)) add('dateMissing', 'info');

    return checks;
};
