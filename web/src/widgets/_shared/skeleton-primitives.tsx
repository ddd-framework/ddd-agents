import { Skeleton } from "../../provider/components";
import React from "react";

interface LineProps {
  width?: number | string;
  height?: number;
  radius?: number;
  className?: string;
}

export const SkeletonLine = ({ width = "100%", height = 12, radius = 6, className }: LineProps) => {
  return <Skeleton className={className} style={{ width, height, borderRadius: radius, display: "block" }} />;
};

interface CircleProps {
  size: number;
  className?: string;
}

export const SkeletonCircle = ({ size, className }: CircleProps) => {
  return (
    <Skeleton
      className={className}
      style={{ width: size, height: size, borderRadius: "50%", display: "inline-block", flexShrink: 0 }}
    />
  );
};

interface PillProps {
  width?: number | string;
  height?: number;
  className?: string;
}

export const SkeletonPill = ({ width = 140, height = 34, className }: PillProps) => {
  return <Skeleton className={className} style={{ width, height, borderRadius: 999, display: "inline-block" }} />;
};

export const SkeletonWidgetLabel = () => {
  return <SkeletonLine width={120} height={10} radius={4} />;
};
