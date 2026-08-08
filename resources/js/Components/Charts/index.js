/**
 * Capa base de gráficos (Paso 0). Importa desde aquí y no desde los archivos
 * individuales: export { ChartCard, EmptyChart } from '@/Components/Charts';
 *
 * Firma esperada de cada componente en su respectivo archivo.
 */

import { TONE, SERIES, tooltipStyle, axisStyle } from './chartTheme';
import ChartCard, { EmptyChart } from './ChartCard';
import ChartTip from './ChartTip';
import TrendArea from './TrendArea';
import CompareBars from './CompareBars';
import FunnelSteps from './FunnelSteps';
import HeatmapGrid from './HeatmapGrid';
import WindowPicker, { DEFAULT_RANGES } from './WindowPicker';

export {
    TONE,
    SERIES,
    tooltipStyle,
    axisStyle,
    ChartCard,
    EmptyChart,
    ChartTip,
    TrendArea,
    CompareBars,
    FunnelSteps,
    HeatmapGrid,
    WindowPicker,
    DEFAULT_RANGES,
};
export { fmtNumber, fmtInteger, fmtMoney, fmtDuration, fmtPct } from './format';