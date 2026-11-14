import { Skeleton } from "../../provider/components";
import React from "react";

/**
 * Neutral loading state shown by ConversationWidgetFeatureGate while another agent space's feature
 * flags are being fetched. The gate can't know the gated widget's own skeleton, so this
 * is a generic widget-sized placeholder.
 */
export const ConversationWidgetGateSkeleton = () => (
  <div data-testid="ConversationWidgetGateSkeleton">
    <Skeleton height="20px" width="40%" />
    <Skeleton height="64px" width="100%" borderRadius="8px" />
  </div>
);
