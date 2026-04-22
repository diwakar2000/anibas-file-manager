export const __ = (text: string, domain: string = 'anibas-file-manager'): string => {
    // Check if running in browser and WordPress i18n is available
    if (typeof window !== 'undefined' && (window as any).wp && (window as any).wp.i18n) {
        return (window as any).wp.i18n.__(text, domain);
    }
    // Fallback translation if not within WordPress or during local dev
    return text;
};

export const _x = (text: string, context: string, domain: string = 'anibas-file-manager'): string => {
    if (typeof window !== 'undefined' && (window as any).wp && (window as any).wp.i18n) {
        return (window as any).wp.i18n._x(text, context, domain);
    }
    return text;
};

export const _n = (single: string, plural: string, number: number, domain: string = 'anibas-file-manager'): string => {
    if (typeof window !== 'undefined' && (window as any).wp && (window as any).wp.i18n) {
        return (window as any).wp.i18n._n(single, plural, number, domain);
    }
    return number === 1 ? single : plural;
};
