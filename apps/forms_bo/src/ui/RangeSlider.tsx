import { formatRangeValue, type RangeConfig } from '@/types';
import { Slider } from 'core-react/ui';

interface RangeSliderProps {
  id?: string;
  'aria-label'?: string;
  config: RangeConfig;
  /** Valeur de l'échelle (pas l'index), ou null : pas encore de réponse. */
  value: number | null;
  onChange: (value: number) => void;
  describedBy?: string;
  invalid?: boolean;
}

/**
 * Curseur d'une question RANGE (specs_fonc §4.7), sur le `Slider` de core-react (input range natif).
 * - Intervalle régulier : min / max / pas passés tels quels.
 * - Valeurs personnalisées (ex. 2, 4, 6, 22, 40, 79) : le curseur avance d'une valeur à la suivante (index
 *   0 → n-1) ; la valeur affichée, lue (`aria-valuetext`) et envoyée est celle de la liste.
 */
export const RangeSlider = ({ id, 'aria-label': ariaLabel, config, value, onChange, describedBy, invalid = false }: RangeSliderProps) => {
  const custom = config.values && config.values.length >= 2 ? config.values : null;
  const format = (v: number) => formatRangeValue(v, config.unit);
  const common = {
    id,
    'aria-label': ariaLabel,
    'aria-describedby': describedBy,
    minLabel: config.minLabel,
    maxLabel: config.maxLabel,
    showValue: true,
    $validationState: invalid ? ('invalid' as const) : undefined,
  };

  if (custom) {
    const index = value === null ? null : Math.max(0, custom.findIndex((v) => Math.abs(v - value) < 1e-9));
    return <Slider {...common} value={index} min={0} max={custom.length - 1} step={1} formatValue={(i) => format(custom[i] ?? custom[0]!)} onChange={(i) => onChange(custom[i] ?? custom[0]!)} />;
  }

  return <Slider {...common} value={value} min={config.min} max={config.max} step={config.step} formatValue={format} onChange={onChange} />;
};
