import type { ComponentType } from "react";
import ReactApexChartImport from "react-apexcharts";
import type { Props as ReactApexChartProps } from "react-apexcharts";

/**
 * The `react-apexcharts` component, whatever the bundler made of its CommonJS export: the app build hands the
 * component itself, the Storybook / preview Vite dev server the module object with the component on `default`
 * (rendering that object throws "Element type is invalid … got: object").
 */
const moduleWithDefault = ReactApexChartImport as unknown as { default?: ComponentType<ReactApexChartProps> };

export const ApexChart: ComponentType<ReactApexChartProps> =
  moduleWithDefault.default ?? (ReactApexChartImport as unknown as ComponentType<ReactApexChartProps>);
