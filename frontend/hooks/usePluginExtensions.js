import { useQuery } from "@tanstack/react-query";
import { useEffect } from "react";
import API from "../includes/API";
import { usePluginLoading } from "../components/PluginLoadingProvider";

export default function usePluginExtensions(surface) {
  const {
    registerSurface,
    unregisterSurface,
    reportSurfaceStatus,
  } = usePluginLoading();

  const query = useQuery({
    queryKey: ["pluginExtensions", surface],
    queryFn: () => API.get("/plugins/ui", { surface }),
    retry: false,
    refetchOnWindowFocus: false,
    staleTime: 30000,
  });

  useEffect(() => {
    if (!surface) {
      return undefined;
    }

    registerSurface(surface);

    return () => unregisterSurface(surface);
  }, [ registerSurface, surface, unregisterSurface ]);

  useEffect(() => {
    if (!surface) {
      return;
    }

    if (query.isPending || query.isFetching) {
      reportSurfaceStatus(surface, "loading");
      return;
    }

    if (query.isSuccess) {
      reportSurfaceStatus(surface, "success");
      return;
    }

    if (query.isError) {
      reportSurfaceStatus(surface, "error");
    }
  }, [
    query.isError,
    query.isFetching,
    query.isPending,
    query.isSuccess,
    reportSurfaceStatus,
    surface,
  ]);

  return query;
}
