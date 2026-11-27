import { Skeleton } from "../../provider/components";
import React from "react";
export const SkeletonLine = ({ width = "100%", height = 12, radius = 6, className }) => {
    return <Skeleton className={className} style={{ width, height, borderRadius: radius, display: "block" }}/>;
};
export const SkeletonCircle = ({ size, className }) => {
    return (<Skeleton className={className} style={{ width: size, height: size, borderRadius: "50%", display: "inline-block", flexShrink: 0 }}/>);
};
export const SkeletonPill = ({ width = 140, height = 34, className }) => {
    return <Skeleton className={className} style={{ width, height, borderRadius: 999, display: "inline-block" }}/>;
};
export const SkeletonWidgetLabel = () => {
    return <SkeletonLine width={120} height={10} radius={4}/>;
};
